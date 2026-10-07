<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Renders {{placeholder}} tokens in email templates.
 *
 * Template bodies are treated as plain text: every character (template text
 * and substituted values alike) is HTML-escaped before line breaks are turned
 * into <br>, so neither a template author nor client data can inject markup.
 */
class TemplateRenderer
{
    public const PLACEHOLDERS = [
        'first_name' => 'Recipient first name',
        'last_name' => 'Recipient last name',
        'full_name' => 'Recipient full name',
        'policy_number' => 'Policy number',
        'product' => 'Product name',
        'policy_owner' => 'Policy owner full name',
        'policy_insured' => 'Policy insured full name',
        'sum_assured' => 'Sum assured',
        'ape' => 'Annualized premium equivalent',
        'issued_date' => 'Policy issue date',
        'policy_years' => 'Whole years since the policy was issued',
        'premium_due' => 'Premium amount per payment (active policies)',
        'due_date' => 'Next premium due date (active policies)',
        'advisor_name' => 'Your name',
        'today' => "Today's date",
    ];

    private const TOKEN = '/\{\{\s*([a-z_]+)\s*\}\}/i';

    /** {{image:12}} — an uploaded EmailImage. Contains ":" and digits, so TOKEN never matches it. */
    private const IMAGE_TOKEN = '/\{\{\s*image:(\d+)\s*\}\}/i';

    /** @return list<int> ids of the images a body references */
    public static function imageIds(string $body): array
    {
        preg_match_all(self::IMAGE_TOKEN, $body, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    public function variables(?Client $client, ?Policy $policy, ?User $advisor): array
    {
        $name = fn (?Client $p) => $p ? trim("{$p->first_name} {$p->last_name}") : null;
        // Next due date and modal premium, as used by the dashboard (active, non-single-pay policies only).
        $due = $policy ? DB::table('vw_premiums_due')->where('policy_id', $policy->id)->first() : null;

        return array_filter([
            'first_name' => $client?->first_name,
            'last_name' => $client?->last_name,
            'full_name' => $name($client),
            'policy_number' => $policy?->policy_number,
            'product' => $policy?->product?->name,
            'policy_owner' => $name($policy?->owner),
            'policy_insured' => $name($policy?->insured),
            'sum_assured' => $policy ? '₱'.number_format((float) $policy->sum_assured, 2) : null,
            'ape' => $policy ? '₱'.number_format((float) $policy->ape, 2) : null,
            'issued_date' => $policy?->issued_date?->format('F j, Y'),
            'policy_years' => $policy?->issued_date ? (string) (int) $policy->issued_date->diffInYears(today()) : null,
            'premium_due' => $due ? '₱'.number_format((float) $due->modal_premium, 2) : null,
            'due_date' => $due ? Carbon::parse($due->next_due_date)->format('F j, Y') : null,
            'advisor_name' => $advisor?->name,
            'today' => now()->format('F j, Y'),
        ], fn ($v) => $v !== null);
    }

    /** Sample values so a template can be previewed without choosing a client. */
    public function sampleVariables(?User $advisor): array
    {
        return [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'full_name' => 'Juan Dela Cruz',
            'policy_number' => 'RB-2026-000123',
            'product' => 'RBEL Life Protect 20',
            'policy_owner' => 'Juan Dela Cruz',
            'policy_insured' => 'Maria Dela Cruz',
            'sum_assured' => '₱2,000,000.00',
            'ape' => '₱48,000.00',
            'issued_date' => now()->subYear()->format('F j, Y'),
            'policy_years' => '1',
            'premium_due' => '₱4,000.00',
            'due_date' => now()->format('F j, Y'),
            'advisor_name' => $advisor?->name ?? 'Your Advisor',
            'today' => now()->format('F j, Y'),
        ];
    }

    /**
     * @param  array<int, string>  $images  image id => src (a URL for previews, "cid:…" when sending)
     * @return array{subject: string, text: string, html: string, unresolved: string[]}
     */
    public function render(string $subject, string $body, array $variables, array $images = []): array
    {
        $unresolved = [];

        $replace = function (string $text) use ($variables, &$unresolved) {
            return preg_replace_callback(self::TOKEN, function ($m) use ($variables, &$unresolved) {
                $key = strtolower($m[1]);
                if (array_key_exists($key, $variables)) {
                    return (string) $variables[$key];
                }
                $unresolved[] = $key;

                return $m[0];
            }, $text);
        };

        $renderedSubject = $replace($subject);
        $text = $replace($body);

        // Images are swapped in AFTER escaping, and only as a tag we build here, so a
        // template can still never inject markup of its own.
        $html = preg_replace_callback(self::IMAGE_TOKEN, function ($m) use ($images, &$unresolved) {
            $id = (int) $m[1];
            if (! isset($images[$id])) {
                $unresolved[] = "image:{$id}";

                return $m[0];
            }

            return '<img src="'.e($images[$id]).'" alt="" style="display:block;max-width:100%;height:auto;margin:8px 0;border:0">';
        }, nl2br(e($text), false));

        return [
            // Subjects are single-line headers: strip CR/LF to prevent header injection.
            'subject' => trim(preg_replace('/[\r\n]+/', ' ', $renderedSubject)),
            'text' => preg_replace(self::IMAGE_TOKEN, '[Image]', $text),
            'html' => $html,
            'unresolved' => array_values(array_unique($unresolved)),
        ];
    }
}

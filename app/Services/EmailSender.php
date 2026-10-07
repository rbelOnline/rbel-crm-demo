<?php

namespace App\Services;

use App\Exceptions\EmailNotSendable;
use App\Mail\TemplatedMail;
use App\Models\EmailImage;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Client;
use App\Models\Policy;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends one templated email to one client and records it in email_logs.
 * Used by the "Send email" dialog and by the daily automations, so both
 * follow the same rules (active template, recipient has an email, every
 * placeholder resolved, images embedded, logged and audited).
 */
class EmailSender
{
    public const NOT_CONFIGURED = 'Email delivery is not set up: MAIL_MAILER=log only writes emails to the log file. Configure SMTP in .env (see README, "Sending email").';

    public function __construct(private TemplateRenderer $renderer) {}

    /**
     * @param  string|null  $dedupeKey  automations only: the email is skipped if one with this key was already sent
     *
     * @throws EmailNotSendable when the email cannot be attempted (nothing is logged)
     */
    public function send(EmailTemplate $template, Client $client, ?Policy $policy, User $sender, ?string $automation = null, ?string $dedupeKey = null): EmailLog
    {
        if ($template->status !== 'active') {
            throw new EmailNotSendable('Only active templates can be sent.');
        }

        if (blank($client->email)) {
            throw new EmailNotSendable('This client has no email address on file.', ['client_id' => ['Client has no email address.']]);
        }

        if ($dedupeKey && EmailLog::where('dedupe_key', $dedupeKey)->exists()) {
            throw new EmailNotSendable('Already sent today.');
        }

        // Images are embedded in the message itself (cid:), so recipients see them without access to this server.
        $inline = EmailImage::whereKey(TemplateRenderer::imageIds($template->body))->get()
            ->filter(fn (EmailImage $i) => is_file($i->absolutePath()))
            ->mapWithKeys(fn (EmailImage $i) => [$i->id => $i]);

        $rendered = $this->renderer->render(
            $template->subject,
            $template->body,
            $this->renderer->variables($client, $policy, $sender),
            $inline->map(fn (EmailImage $i) => 'cid:'.TemplatedMail::contentId($i))->all(),
        );

        if ($missingImages = array_filter($rendered['unresolved'], fn ($u) => str_starts_with($u, 'image:'))) {
            throw new EmailNotSendable('This template refers to an image that no longer exists ('.implode(', ', $missingImages).'). Edit the template and insert the image again.');
        }

        if ($rendered['unresolved'] !== []) {
            throw new EmailNotSendable(
                'Some placeholders have no value: '.implode(', ', $rendered['unresolved']).'. Choose a policy or edit the template.',
                ['policy_id' => ['Missing values for: '.implode(', ', $rendered['unresolved'])]],
            );
        }

        try {
            $log = EmailLog::create([
                'email_template_id' => $template->id,
                'user_id' => $sender->id,
                'client_id' => $client->id,
                'policy_id' => $policy?->id,
                'automation' => $automation,
                'dedupe_key' => $dedupeKey,
                'recipient' => $client->email,
                'subject' => $rendered['subject'],
                'status' => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another run claimed the same key a moment ago.
            throw new EmailNotSendable('Already sent today.');
        }

        if (config('mail.default') === 'log') {
            // The "log" mailer only writes the message to storage/logs; never record that as sent.
            $log->update(['status' => 'failed', 'error_message' => self::NOT_CONFIGURED, 'dedupe_key' => null]);
        } else {
            try {
                Mail::to($client->email, "{$client->first_name} {$client->last_name}")
                    ->send(new TemplatedMail($rendered['subject'], $rendered['html'], $rendered['text'], $inline->values()->all(), $sender));

                $log->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (\Throwable $e) {
                report($e);
                // Release the key so a later run can retry this recipient.
                $log->update(['status' => 'failed', 'error_message' => Str::limit($e->getMessage(), 480), 'dedupe_key' => null]);
            }
        }

        AuditLogger::record('sent_email', 'email_templates', $template->id, null,
            ['email_log_id' => $log->id, 'client_id' => $client->id, 'status' => $log->status, 'automation' => $automation],
            ($automation ? "Automation sent" : 'Sent')." \"{$template->name}\" to client #{$client->id}",
            $sender->id);

        return $log;
    }
}

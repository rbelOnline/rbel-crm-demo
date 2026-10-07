<?php

namespace App\Services;

use App\Exceptions\EmailNotSendable;
use App\Models\Automation;
use App\Models\EmailLog;
use App\Models\Client;
use App\Models\Policy;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily email automations:
 *  - birthday_greeting: every client whose birthday is today
 *    (Feb 29 birthdays are greeted on Feb 28 in non-leap years);
 *  - premium_due: the Policy OWNER (the payer) of every active policy whose next
 *    premium falls due today (vw_premiums_due, as on the dashboard);
 *  - policy_anniversary: the Policy OWNER (the policyholder) of every active policy
 *    issued on today's month and day in an earlier year (Feb 29 issues are
 *    celebrated on Feb 28 in non-leap years).
 *
 * Each recipient gets a dedupe key per day, so running again (a later scheduled
 * run, or "Run now") never sends the same greeting or reminder twice.
 */
class AutomationService
{
    /** Cache key holding the last time the scheduler checked the automations. */
    public const HEARTBEAT_KEY = 'automations:heartbeat';

    public function __construct(private EmailSender $sender) {}

    /**
     * Today's recipients and whether each will be sent.
     *
     * @return list<array{key: string, client: Client, policy: ?Policy, detail: string, status: 'ready'|'no_email'|'already_sent'}>
     */
    public function candidates(Automation $automation, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $date = $today->toDateString();

        $items = match ($automation->key) {
            Automation::BIRTHDAY => $this->birthdays($today)->map(fn (Client $p) => [
                'key' => "birthday_greeting:{$date}:client:{$p->id}",
                'client' => $p,
                'policy' => null,
                'detail' => 'Turns '.($today->year - $p->birthdate->year),
            ]),
            Automation::PREMIUM_DUE => $this->premiumsDue($date)->map(fn (array $row) => [
                'key' => "premium_due:{$date}:policy:{$row['policy']->id}",
                'client' => $row['policy']->owner,
                'policy' => $row['policy'],
                'detail' => "Policy {$row['policy']->policy_number} · ₱".number_format($row['amount'], 2).' due',
            ]),
            Automation::ANNIVERSARY => $this->anniversaries($today)->map(fn (Policy $p) => [
                'key' => "policy_anniversary:{$date}:policy:{$p->id}",
                'client' => $p->owner,
                'policy' => $p,
                'detail' => "Policy {$p->policy_number} · ".self::ordinal($today->year - $p->issued_date->year).' anniversary',
            ]),
            default => collect(),
        };

        $sent = EmailLog::whereIn('dedupe_key', $items->pluck('key'))->pluck('dedupe_key')->flip();

        return $items->map(fn (array $item) => $item + [
            'status' => match (true) {
                $sent->has($item['key']) => 'already_sent',
                blank($item['client']?->email) => 'no_email',
                default => 'ready',
            },
        ])->values()->all();
    }

    /** Should the scheduler run this automation now? Once its send time has passed and it has not run since. */
    public function isDue(Automation $automation, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $sendAt = $now->startOfDay()->setTimeFromTimeString($automation->sendTime());

        return $automation->enabled
            && $automation->email_template_id
            && $now->greaterThanOrEqualTo($sendAt)
            && (! $automation->last_run_at || $automation->last_run_at->lt($sendAt));
    }

    /**
     * Send today's emails for one automation and store the run summary.
     *
     * @param  string  $trigger  'schedule' or 'manual'
     */
    public function run(Automation $automation, string $trigger = 'schedule', ?User $triggeredBy = null): array
    {
        $automation->loadMissing(['template', 'sender']);
        $summary = ['date' => today()->toDateString(), 'trigger' => $trigger, 'total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'error' => null, 'items' => []];

        $sender = $automation->sender ?? $triggeredBy ?? User::where('role', 'admin')->orderBy('id')->first();

        if (! $automation->template) {
            $summary['error'] = 'No email template selected.';
        } elseif (! $sender) {
            $summary['error'] = 'No sending user selected.';
        } else {
            foreach ($this->candidates($automation) as $c) {
                $summary['total']++;
                $item = ['name' => $c['client']?->displayName() ?? '—', 'detail' => $c['detail']];

                if ($c['status'] !== 'ready') {
                    $summary['skipped']++;
                    $summary['items'][] = $item + ['status' => 'skipped', 'reason' => $c['status'] === 'no_email' ? 'No email address' : 'Already sent today'];

                    continue;
                }

                try {
                    $log = $this->sender->send($automation->template, $c['client'], $c['policy'], $sender, $automation->key, $c['key']);
                    $summary[$log->status === 'sent' ? 'sent' : 'failed']++;
                    $summary['items'][] = $item + ['status' => $log->status, 'reason' => $log->error_message];
                } catch (EmailNotSendable $e) {
                    $summary['skipped']++;
                    $summary['items'][] = $item + ['status' => 'skipped', 'reason' => $e->getMessage()];
                }
            }
        }

        $summary['items'] = array_slice($summary['items'], 0, 200);
        $automation->forceFill(['last_run_at' => now(), 'last_run_summary' => $summary])->saveQuietly();

        AuditLogger::record('automation_run', 'automations', $automation->id, null,
            collect($summary)->except('items')->all(),
            "{$automation->label()}: {$summary['sent']} sent, {$summary['failed']} failed, {$summary['skipped']} skipped ({$trigger})",
            $triggeredBy?->id);

        return $summary;
    }

    /** @return \Illuminate\Support\Collection<int, Client> */
    private function birthdays(CarbonImmutable $today)
    {
        // Feb 29 birthdays are greeted on Feb 28 in non-leap years.
        $days = [$today->day];
        if (! $today->isLeapYear() && $today->format('md') === '0228') {
            $days[] = 29;
        }

        return Client::query()
            ->whereMonth('birthdate', $today->month)
            ->whereIn(DB::raw('DAY(birthdate)'), $days)
            ->orderBy('last_name')->orderBy('first_name')->get();
    }

    /** @return \Illuminate\Support\Collection<int, Policy> */
    private function anniversaries(CarbonImmutable $today)
    {
        $days = [$today->day];
        if (! $today->isLeapYear() && $today->format('md') === '0228') {
            $days[] = 29;
        }

        return Policy::with(['owner', 'insured', 'product'])
            ->where('status', 'active')
            ->where('issued_month', $today->month)
            ->whereIn(DB::raw('DAY(issued_date)'), $days)
            ->whereYear('issued_date', '<', $today->year)
            ->orderBy('policy_number')
            ->get();
    }

    /** 1st, 2nd, 3rd, 4th … 11th, 12th, 13th, 21st */
    private static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    /** @return \Illuminate\Support\Collection<int, array{policy: Policy, amount: float}> */
    private function premiumsDue(string $date)
    {
        $due = DB::table('vw_premiums_due')->where('next_due_date', $date)->get(['policy_id', 'modal_premium'])->keyBy('policy_id');

        return Policy::with(['owner', 'insured', 'product'])->whereKey($due->keys())->orderBy('policy_number')->get()
            ->map(fn (Policy $p) => ['policy' => $p, 'amount' => (float) $due[$p->id]->modal_premium]);
    }
}

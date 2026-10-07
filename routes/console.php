<?php

use App\Models\Automation;
use App\Services\AutomationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

/*
| Daily email automations. The scheduler checks every minute; each enabled automation
| sends once its own send time has passed (and never twice per recipient per day).
| Runs via the "RBEL-CRM Scheduler" Windows task (install-scheduler.ps1), which calls
| `php artisan schedule:run` every minute; `start-scheduler.bat` is a manual alternative.
*/
Schedule::command('crm:automations')->everyMinute()->withoutOverlapping();

Artisan::command('crm:automations {--force : Run enabled automations now, ignoring their send time} {--dry-run : Only list today\'s recipients}', function (AutomationService $service) {
    // Heartbeat shown on the Automations page, so it is clear whether automatic sending is live.
    Cache::forever(AutomationService::HEARTBEAT_KEY, now()->toIso8601String());

    $automations = Automation::with(['template', 'sender'])->orderBy('id')->get();

    foreach ($automations as $automation) {
        if ($this->option('dry-run')) {
            $candidates = $service->candidates($automation);
            $this->info("{$automation->label()}: ".count($candidates).' today'.($automation->enabled ? '' : ' (disabled)'));
            foreach ($candidates as $c) {
                $this->line("  - {$c['client']?->displayName()} <{$c['client']?->email}> {$c['detail']} [{$c['status']}]");
            }

            continue;
        }

        if (! $automation->enabled || ! ($this->option('force') ? $automation->email_template_id : $service->isDue($automation))) {
            continue;
        }

        $s = $service->run($automation, 'schedule');
        $this->info("{$automation->label()}: {$s['sent']} sent, {$s['failed']} failed, {$s['skipped']} skipped".($s['error'] ? " — {$s['error']}" : ''));
    }
})->purpose('Send today\'s birthday greetings, payment reminders and policy anniversary emails (automations)');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('crm:mail-test {to : Address to send the test email to}', function (string $to) {
    $mailer = config('mail.default');
    $from = config('mail.from.address');
    $this->line("Mailer: {$mailer}   Host: ".config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port')."   From: {$from}");

    if ($mailer === 'log') {
        $this->error('MAIL_MAILER=log: emails are only written to storage/logs, not sent. Set the SMTP settings in .env first.');

        return 1;
    }

    if ($mailer === 'smtp' && strcasecmp((string) config('mail.mailers.smtp.username'), (string) $from) !== 0) {
        $this->warn('MAIL_FROM_ADDRESS differs from MAIL_USERNAME; Gmail will rewrite or reject the sender. Make them the same address.');
    }

    try {
        Mail::raw('This is a test email from '.config('app.name').'. If you can read this, email delivery works.', fn ($m) => $m->to($to)->subject(config('app.name').' test email'));
    } catch (Throwable $e) {
        $this->error('Sending failed: '.$e->getMessage());

        return 1;
    }

    $this->info("Test email sent to {$to}. Check the inbox (and the spam folder).");

    return 0;
})->purpose('Send a test email to check the mail settings');

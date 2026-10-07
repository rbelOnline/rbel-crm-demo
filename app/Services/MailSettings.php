<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Outgoing mail server settings edited in the app (Profile, admins) instead of .env.
 * Stored as one app_settings row; the SMTP password is encrypted with APP_KEY and
 * never returned to the browser. When no settings are saved, .env is used as before.
 */
class MailSettings
{
    private const KEY = 'mail';

    private const CACHE_KEY = 'app-settings:mail';

    /** STARTTLS on 587 (Gmail, Outlook) or implicit SSL/TLS on 465. */
    public const ENCRYPTIONS = ['tls', 'ssl'];

    /** Saved settings, or null when the app still uses .env. */
    public function stored(): ?array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $row = DB::table('app_settings')->where('key', self::KEY)->value('value');

            return $row ? json_decode($row, true) : null;
        }) ?: null;
    }

    /** Override the mail config for this process with the saved settings (called at boot). */
    public function apply(): void
    {
        $s = $this->stored();
        if (! $s) {
            return;
        }

        config([
            'mail.default' => $s['enabled'] ? 'smtp' : 'log',
            'mail.mailers.smtp.host' => $s['host'],
            'mail.mailers.smtp.port' => (int) $s['port'],
            'mail.mailers.smtp.scheme' => ($s['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => $s['username'] ?: null,
            'mail.mailers.smtp.password' => $this->decrypt($s['password'] ?? null),
            'mail.from.address' => ($s['from_address'] ?? null) ?: ($s['username'] ?: config('mail.from.address')),
            'mail.from.name' => ($s['from_name'] ?? null) ?: config('app.name'),
        ]);

        // A mailer resolved before this call would still hold the old transport.
        Mail::purge('smtp');
    }

    /** Values for the settings form (saved, else current .env), never the password itself. */
    public function forForm(): array
    {
        $s = $this->stored();
        $smtp = config('mail.mailers.smtp');

        return [
            'source' => $s ? 'app' : 'env',
            'enabled' => $s ? (bool) $s['enabled'] : config('mail.default') === 'smtp',
            'host' => $s['host'] ?? $smtp['host'],
            'port' => (int) ($s['port'] ?? $smtp['port']),
            'encryption' => $s['encryption'] ?? (($smtp['scheme'] ?? null) === 'smtps' || (int) $smtp['port'] === 465 ? 'ssl' : 'tls'),
            'username' => $s['username'] ?? $smtp['username'],
            'password_set' => $s ? filled($s['password'] ?? null) : filled($smtp['password']),
            // Blank means "same as the username" (what Gmail requires anyway).
            'from_address' => $s['from_address'] ?? null,
            'from_name' => $s['from_name'] ?? config('mail.from.name'),
            'updated_at' => $s ? DB::table('app_settings')->where('key', self::KEY)->value('updated_at') : null,
        ];
    }

    /**
     * Save the settings. A blank password keeps the current one (the saved one, or
     * the .env password the first time), so it never has to be retyped.
     */
    public function save(array $data, User $by): void
    {
        $current = $this->stored();
        $newPassword = filled($data['password'] ?? null);
        $password = $newPassword
            ? Crypt::encryptString($data['password'])
            : ($current['password'] ?? (filled(config('mail.mailers.smtp.password')) ? Crypt::encryptString(config('mail.mailers.smtp.password')) : null));

        $value = [
            'enabled' => (bool) $data['enabled'],
            'host' => trim((string) $data['host']),
            'port' => (int) $data['port'],
            'encryption' => $data['encryption'],
            'username' => filled($data['username'] ?? null) ? trim($data['username']) : null,
            'password' => $password,
            'from_address' => filled($data['from_address'] ?? null) ? trim($data['from_address']) : null,
            'from_name' => filled($data['from_name'] ?? null) ? trim($data['from_name']) : null,
        ];

        DB::table('app_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($value), 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => $current ? DB::raw('created_at') : now()],
        );
        Cache::forget(self::CACHE_KEY);
        $this->apply();

        // Audit what changed, never the password itself.
        $public = fn (?array $v) => $v ? array_diff_key($v, ['password' => true]) : null;
        AuditLogger::record('updated', 'mail_settings', null, $public($current), $public($value) + ['password_changed' => $newPassword], 'Updated email sending settings', $by->id);
    }

    private function decrypt(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // APP_KEY changed since saving: the password must be entered again.
            report(new \RuntimeException('Saved mail password could not be decrypted (APP_KEY changed?).'));

            return null;
        }
    }
}

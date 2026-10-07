<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'crm.sender@gmail.com',
            'password' => 'abcd efgh ijkl mnop',
            'from_address' => null,
            'from_name' => 'RBEL-CRM',
        ], $overrides);
    }

    public function test_only_admins_can_view_or_change_mail_settings(): void
    {
        $this->signIn('advisor');
        $this->getJson('/api/mail-settings')->assertForbidden();
        $this->putJson('/api/mail-settings', $this->payload())->assertForbidden();
        $this->postJson('/api/mail-settings/test')->assertForbidden();
    }

    public function test_form_starts_from_env_until_saved(): void
    {
        $this->signIn('admin');
        config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.password' => 'from-env']);

        $this->getJson('/api/mail-settings')->assertOk()
            ->assertJsonPath('data.source', 'env')
            ->assertJsonPath('data.host', 'smtp.example.test')
            ->assertJsonPath('data.password_set', true)
            ->assertJsonMissingPath('data.password');
    }

    public function test_saving_encrypts_the_password_applies_the_settings_and_never_returns_the_password(): void
    {
        $admin = $this->signIn('admin');

        $this->putJson('/api/mail-settings', $this->payload())->assertOk()
            ->assertJsonPath('data.source', 'app')
            ->assertJsonPath('data.password_set', true)
            ->assertJsonMissingPath('data.password');

        // Stored encrypted, not in plain text.
        $raw = DB::table('app_settings')->where('key', 'mail')->value('value');
        $this->assertStringNotContainsString('abcd efgh', $raw);

        // Applied to the running app: SMTP on, Gmail host, sender defaults to the username.
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.gmail.com', config('mail.mailers.smtp.host'));
        $this->assertSame('abcd efgh ijkl mnop', config('mail.mailers.smtp.password'));
        $this->assertSame('crm.sender@gmail.com', config('mail.from.address'));
        $this->getJson('/api/meta')->assertJsonPath('data.email_delivery_enabled', true);

        // Audited without the password.
        $log = AuditLog::where(['module' => 'mail_settings', 'action' => 'updated'])->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertTrue($log->new_values['password_changed']);
    }

    public function test_blank_password_keeps_the_saved_one_and_ssl_uses_smtps(): void
    {
        $this->signIn('admin');
        $this->putJson('/api/mail-settings', $this->payload())->assertOk();

        $this->putJson('/api/mail-settings', $this->payload(['password' => '', 'port' => 465, 'encryption' => 'ssl', 'from_address' => 'hello@rbel.test']))->assertOk();

        $this->assertSame('abcd efgh ijkl mnop', config('mail.mailers.smtp.password'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('hello@rbel.test', config('mail.from.address'));
    }

    public function test_turning_sending_off_switches_to_log_only(): void
    {
        $this->signIn('admin');
        $this->putJson('/api/mail-settings', $this->payload(['enabled' => false, 'host' => '', 'username' => '']))->assertOk();

        $this->assertSame('log', config('mail.default'));
        $this->postJson('/api/mail-settings/test')->assertUnprocessable()->assertJsonPath('message', 'Email sending is turned off. Turn it on and save first.');
    }

    public function test_validation(): void
    {
        $this->signIn('admin');
        $this->putJson('/api/mail-settings', $this->payload(['host' => '', 'port' => 70000, 'encryption' => 'starttls', 'username' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['host', 'port', 'encryption', 'username']);

        // Username is not an email address, so a sender address is required.
        $this->putJson('/api/mail-settings', $this->payload(['username' => 'apikey', 'from_address' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('from_address');
    }
}

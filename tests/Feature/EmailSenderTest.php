<?php

namespace Tests\Feature;

use App\Http\Controllers\EmailTemplateController;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Client;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailSenderTest extends TestCase
{
    private function sendAs(string $name, string $email): \Illuminate\Testing\TestResponse
    {
        $user = $this->signIn('advisor');
        $user->update(['name' => $name, 'email' => $email]);
        $template = EmailTemplate::factory()->create(['status' => 'active', 'subject' => 'Hi {{first_name}}', 'body' => 'Hello {{first_name}}']);
        $person = Client::factory()->create(['first_name' => 'Ana', 'email' => 'ana@example.test']);

        return $this->postJson("/api/email-templates/{$template->id}/send", ['client_id' => $person->id]);
    }

    public function test_email_is_sent_from_the_app_mailbox_named_after_the_user_with_reply_to_the_user(): void
    {
        config(['mail.from.address' => 'crm.sender@gmail.com', 'app.name' => 'RBEL-CRM']);

        $this->sendAs('Maria Santos', 'maria.santos@example.test')->assertCreated();

        /** @var Email $email */
        $email = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $from = $email->getFrom()[0];
        $this->assertSame('crm.sender@gmail.com', $from->getAddress());
        $this->assertSame('Maria Santos via RBEL-CRM', $from->getName());
        $this->assertSame('maria.santos@example.test', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('Maria Santos', $email->getReplyTo()[0]->getName());
    }

    public function test_log_mailer_is_reported_as_not_configured_instead_of_sent(): void
    {
        config(['mail.default' => 'log']);

        $this->sendAs('Maria Santos', 'maria.santos@example.test')
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', EmailTemplateController::NOT_CONFIGURED);

        $this->assertSame('failed', EmailLog::latest('id')->value('status'));
        $this->getJson('/api/meta')->assertJsonPath('data.email_delivery_enabled', false);
    }

    public function test_meta_reports_delivery_enabled_for_real_mailers(): void
    {
        $this->signIn();
        config(['mail.default' => 'smtp']);
        $this->getJson('/api/meta')->assertJsonPath('data.email_delivery_enabled', true);
    }
}

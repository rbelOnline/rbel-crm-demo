<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Client;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    public function test_template_crud_and_duplicate(): void
    {
        $this->signIn('admin');

        $id = $this->postJson('/api/email-templates', [
            'name' => 'Anniversary', 'subject' => 'Policy {{policy_number}}', 'body' => 'Hi {{first_name}}', 'status' => 'active',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/email-templates', ['name' => 'Anniversary', 'subject' => 'x', 'body' => 'y', 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->postJson("/api/email-templates/{$id}/duplicate")->assertCreated()
            ->assertJsonPath('data.name', 'Anniversary (copy)')->assertJsonPath('data.status', 'draft');
        $this->postJson("/api/email-templates/{$id}/duplicate")->assertCreated()->assertJsonPath('data.name', 'Anniversary (copy 2)');

        $this->putJson("/api/email-templates/{$id}", ['name' => 'Anniversary', 'subject' => 'Updated', 'body' => 'B', 'status' => 'draft'])
            ->assertOk()->assertJsonPath('data.subject', 'Updated');

        $this->getJson('/api/email-templates')->assertOk()->assertJsonCount(3, 'data')->assertJsonStructure(['placeholders']);
        $this->deleteJson("/api/email-templates/{$id}")->assertNoContent();
    }

    public function test_preview_renders_owner_and_insured_placeholders_and_escapes_html(): void
    {
        $this->signIn();
        ['juan' => $juan, 'a' => $a] = $this->ownerInsuredScenario();
        $juan->update(['first_name' => '<b>Juan</b>']);

        $template = EmailTemplate::factory()->create([
            'subject' => 'About {{policy_number}}',
            'body' => "Hello {{first_name}}\nOwner: {{policy_owner}}\nInsured: {{policy_insured}}\n{{unknown_tag}}",
        ]);

        $data = $this->postJson("/api/email-templates/{$template->id}/preview", ['client_id' => $juan->id, 'policy_id' => $a->id])
            ->assertOk()->json('data');

        $this->assertSame('About RB-A-0001', $data['subject']);
        // Names are saved in Title Case, so the tag reads <B>; it must still be escaped.
        $this->assertStringContainsString('&lt;B&gt;Juan&lt;/B&gt;', $data['html']);
        $this->assertStringNotContainsString('<b>', $data['html']);
        $this->assertStringContainsString('Insured: Maria Dela Cruz', $data['text']);
        $this->assertSame(['unknown_tag'], $data['unresolved']);

        // Sample data when no client is chosen.
        $this->postJson("/api/email-templates/{$template->id}/preview")->assertOk()->assertJsonPath('data.uses_sample_data', true);

        // Policy must belong to the recipient.
        ['pedro' => $pedro] = ['pedro' => Client::factory()->create()];
        $this->postJson("/api/email-templates/{$template->id}/preview", ['client_id' => $pedro->id, 'policy_id' => $a->id])
            ->assertUnprocessable()->assertJsonValidationErrors('policy_id');
    }

    public function test_send_creates_masked_email_log(): void
    {
        Mail::fake();
        $this->signIn('advisor');
        $person = Client::factory()->create(['email' => 'maria.santos@example.test', 'first_name' => 'Maria']);
        $template = EmailTemplate::factory()->create();

        $this->postJson("/api/email-templates/{$template->id}/send", ['client_id' => $person->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.recipient', 'm***@example.test');

        Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo('maria.santos@example.test') && $m->renderedSubject === 'Hello Maria');
        $this->assertSame(1, EmailLog::where('status', 'sent')->count());

        $this->getJson('/api/email-logs')->assertOk()->assertJsonPath('data.0.recipient', 'm***@example.test');
    }

    public function test_send_guards(): void
    {
        Mail::fake();
        $this->signIn('advisor');
        $noEmail = Client::factory()->create(['email' => null]);
        $person = Client::factory()->create();

        $draft = EmailTemplate::factory()->create(['status' => 'draft']);
        $this->postJson("/api/email-templates/{$draft->id}/send", ['client_id' => $person->id])->assertUnprocessable();

        $active = EmailTemplate::factory()->create();
        $this->postJson("/api/email-templates/{$active->id}/send", ['client_id' => $noEmail->id])->assertUnprocessable();

        $needsPolicy = EmailTemplate::factory()->create(['body' => 'Policy {{policy_number}}']);
        $this->postJson("/api/email-templates/{$needsPolicy->id}/send", ['client_id' => $person->id])
            ->assertUnprocessable()->assertJsonValidationErrors('policy_id');

        $this->postJson("/api/email-templates/{$active->id}/send", [])->assertUnprocessable()->assertJsonValidationErrors('client_id');

        Mail::assertNothingSent();

        $this->signIn('assistant');
        $this->postJson("/api/email-templates/{$active->id}/send", ['client_id' => $person->id])->assertForbidden();
    }

    public function test_failed_send_is_logged(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $this->signIn();
        $person = Client::factory()->create();
        $template = EmailTemplate::factory()->create();

        $this->postJson("/api/email-templates/{$template->id}/send", ['client_id' => $person->id])
            ->assertStatus(502)->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_message', 'SMTP down');
    }
}

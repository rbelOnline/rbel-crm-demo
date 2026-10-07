<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\EmailLog;
use App\Models\Lead;
use App\Models\EmailTemplate;
use App\Models\Client;
use App\Models\Policy;
use App\Services\AutomationService;
use Carbon\CarbonImmutable;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    private function automation(string $key, string $subject, string $body, array $attrs = []): Automation
    {
        $template = EmailTemplate::factory()->create(['status' => 'active', 'subject' => $subject, 'body' => $body]);
        $automation = Automation::where('key', $key)->firstOrFail();
        $automation->update(array_merge(['enabled' => true, 'email_template_id' => $template->id, 'send_time' => '00:00'], $attrs));

        return $automation->refresh();
    }

    /** @return list<Email> */
    private function sent(): array
    {
        return array_map(fn ($m) => $m->getOriginalMessage(), app('mailer')->getSymfonyTransport()->messages()->all());
    }

    public function test_birthday_greetings_go_to_clients_once_per_day(): void
    {
        $admin = $this->signIn('admin');
        $automation = $this->automation(Automation::BIRTHDAY, 'Happy birthday, {{first_name}}!', 'Dear {{first_name}}, happy birthday!', ['sender_user_id' => $admin->id]);
        $bornToday = now()->subYears(40)->toDateString();

        $client = Client::factory()->born($bornToday)->create(['first_name' => 'Ana', 'email' => 'ana@example.test']);
        // Insured-only clients are greeted too.
        $insured = Client::factory()->insuredOnly()->born($bornToday)->create(['first_name' => 'Ben', 'email' => 'ben@example.test']);
        $noEmail = Client::factory()->born($bornToday)->create(['first_name' => 'Cai', 'email' => null]);
        // Leads are not clients: no greeting (and no client to log it against).
        Lead::factory()->create(['birthdate' => $bornToday, 'email' => 'hidden@example.test']);
        Client::factory()->born(now()->subYears(40)->addDay()->toDateString())->create(['email' => 'tomorrow@example.test']);

        $summary = app(AutomationService::class)->run($automation);

        $this->assertSame([2, 0, 1], [$summary['sent'], $summary['failed'], $summary['skipped']]);
        $this->assertEqualsCanonicalizing(['ana@example.test', 'ben@example.test'], array_map(fn (Email $e) => $e->getTo()[0]->getAddress(), $this->sent()));
        $this->assertSame('Happy birthday, Ana!', collect($this->sent())->first(fn (Email $e) => $e->getTo()[0]->getAddress() === 'ana@example.test')->getSubject());
        $this->assertSame(2, EmailLog::where('automation', Automation::BIRTHDAY)->where('status', 'sent')->count());

        // Running again the same day sends nothing new.
        $again = app(AutomationService::class)->run($automation->refresh());
        $this->assertSame(0, $again['sent']);
        $this->assertSame(3, $again['skipped']);
        $this->assertCount(2, $this->sent());

        $statuses = collect(app(AutomationService::class)->candidates($automation))->mapWithKeys(fn ($c) => [$c['client']->id => $c['status']]);
        $this->assertSame(['already_sent', 'already_sent', 'no_email'], [$statuses[$client->id], $statuses[$insured->id], $statuses[$noEmail->id]]);
    }

    public function test_payment_reminders_go_to_the_policy_owner_with_amount_and_due_date(): void
    {
        $this->signIn('admin');
        $automation = $this->automation(Automation::PREMIUM_DUE, 'Premium due for {{policy_number}}', 'Hi {{first_name}}, {{premium_due}} is due on {{due_date}}.');
        $owner = Client::factory()->create(['first_name' => 'Olivia', 'email' => 'owner@example.test']);
        $insured = Client::factory()->create(['first_name' => 'Ian', 'email' => 'insured@example.test']);

        // Annual policy issued exactly a year ago: next premium due today (₱12,000 / year).
        $due = Policy::factory()->ownedBy($owner)->insuring($insured)->create([
            'policy_number' => 'RB-DUE-0001', 'mode_of_payment' => 'annual', 'status' => 'active', 'ape' => 12000,
            'issued_date' => now()->subYear()->toDateString(),
        ]);
        // Not due today.
        Policy::factory()->ownedBy($owner)->insuring($insured)->create(['mode_of_payment' => 'annual', 'status' => 'active', 'issued_date' => now()->subYear()->addDays(3)->toDateString()]);

        $summary = app(AutomationService::class)->run($automation);

        $this->assertSame(1, $summary['sent']);
        $email = $this->sent()[0];
        $this->assertSame('owner@example.test', $email->getTo()[0]->getAddress()); // the payer, never the insured
        $this->assertSame('Premium due for RB-DUE-0001', $email->getSubject());
        $this->assertStringContainsString('₱12,000.00 is due on '.now()->format('F j, Y'), $email->getTextBody());
        $this->assertSame($due->id, EmailLog::where('automation', Automation::PREMIUM_DUE)->value('policy_id'));
    }

    public function test_policy_anniversaries_go_to_the_policy_owner_of_active_policies(): void
    {
        $this->signIn('admin');
        $automation = $this->automation(Automation::ANNIVERSARY, 'Policy {{policy_number}} anniversary', 'Hi {{first_name}}, {{policy_years}} years since {{issued_date}}.');
        $owner = Client::factory()->create(['first_name' => 'Olivia', 'email' => 'owner@example.test']);
        $insured = Client::factory()->create(['first_name' => 'Ian', 'email' => 'insured@example.test']);
        $issued = now()->subYears(3)->toDateString();

        $policy = Policy::factory()->ownedBy($owner)->insuring($insured)->create(['policy_number' => 'RB-ANN-0001', 'status' => 'active', 'issued_date' => $issued]);
        // Not today: lapsed, issued this year, or a different day.
        Policy::factory()->ownedBy($owner)->insuring($insured)->create(['status' => 'lapsed', 'issued_date' => $issued]);
        Policy::factory()->ownedBy($owner)->insuring($insured)->create(['status' => 'active', 'issued_date' => now()->toDateString()]);
        Policy::factory()->ownedBy($owner)->insuring($insured)->create(['status' => 'active', 'issued_date' => now()->subYears(3)->addDay()->toDateString()]);

        $candidates = app(AutomationService::class)->candidates($automation);
        $this->assertCount(1, $candidates);
        $this->assertSame('Policy RB-ANN-0001 · 3rd anniversary', $candidates[0]['detail']);

        $summary = app(AutomationService::class)->run($automation);

        $this->assertSame(1, $summary['sent']);
        $email = $this->sent()[0];
        $this->assertSame('owner@example.test', $email->getTo()[0]->getAddress()); // the policyholder, never the insured
        $this->assertSame('Policy RB-ANN-0001 anniversary', $email->getSubject());
        $this->assertStringContainsString('3 years since '.now()->subYears(3)->format('F j, Y'), $email->getTextBody());
        $this->assertSame($policy->id, EmailLog::where('automation', Automation::ANNIVERSARY)->value('policy_id'));
    }

    public function test_feb_29_policy_anniversaries_fall_on_feb_28_in_non_leap_years(): void
    {
        $this->signIn('admin');
        $automation = $this->automation(Automation::ANNIVERSARY, 'Hi', 'Happy anniversary!');
        $policy = Policy::factory()->create(['status' => 'active', 'issued_date' => '2024-02-29']);

        $ids = fn (string $day) => collect(app(AutomationService::class)->candidates($automation, CarbonImmutable::parse($day)))->pluck('policy.id')->all();

        $this->assertSame([$policy->id], $ids('2025-02-28'));
        $this->assertSame([], $ids('2028-02-28'));
        $this->assertSame([$policy->id], $ids('2028-02-29'));
    }

    public function test_a_failed_send_can_be_retried_later_the_same_day(): void
    {
        $this->signIn('admin');
        $automation = $this->automation(Automation::BIRTHDAY, 'Hi', 'Happy birthday!');
        Client::factory()->born(now()->subYears(30)->toDateString())->create(['email' => 'ana@example.test']);

        config(['mail.default' => 'log']); // delivery not configured: recorded as failed
        $this->assertSame(1, app(AutomationService::class)->run($automation)['failed']);

        config(['mail.default' => 'array']);
        $this->assertSame(1, app(AutomationService::class)->run($automation->refresh())['sent']);
    }

    public function test_is_due_after_send_time_once_per_day(): void
    {
        $service = app(AutomationService::class);
        $automation = $this->automation(Automation::BIRTHDAY, 'Hi', 'Hi', ['send_time' => '08:00']);
        $today = CarbonImmutable::today();

        $this->assertFalse($service->isDue($automation, $today->setTime(7, 59)));
        $this->assertTrue($service->isDue($automation, $today->setTime(8, 0)));

        $automation->forceFill(['last_run_at' => $today->setTime(8, 5)])->save();
        $this->assertFalse($service->isDue($automation, $today->setTime(9, 0)));
        $this->assertTrue($service->isDue($automation, $today->addDay()->setTime(8, 0)));

        $automation->update(['enabled' => false]);
        $this->assertFalse($service->isDue($automation, $today->addDay()->setTime(8, 0)));
    }

    public function test_settings_api_is_admin_only_and_needs_an_active_template_to_enable(): void
    {
        $this->signIn('advisor');
        $automation = Automation::where('key', Automation::BIRTHDAY)->firstOrFail();
        $this->getJson('/api/automations')->assertForbidden();

        $this->signIn('admin');
        $this->getJson('/api/automations')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.key', Automation::BIRTHDAY)->assertJsonPath('data.2.key', Automation::ANNIVERSARY);

        $draft = EmailTemplate::factory()->create(['status' => 'draft']);
        $this->putJson("/api/automations/{$automation->id}", ['enabled' => true, 'email_template_id' => null, 'send_time' => '07:30'])
            ->assertUnprocessable()->assertJsonValidationErrors('email_template_id');
        $this->putJson("/api/automations/{$automation->id}", ['enabled' => true, 'email_template_id' => $draft->id, 'send_time' => '07:30'])
            ->assertUnprocessable()->assertJsonValidationErrors('email_template_id');

        $active = EmailTemplate::factory()->create(['status' => 'active']);
        $this->putJson("/api/automations/{$automation->id}", ['enabled' => true, 'email_template_id' => $active->id, 'send_time' => '07:30'])
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.send_time', '07:30');

        Client::factory()->born(now()->subYears(20)->toDateString())->create(['first_name' => 'Zed', 'email' => 'zed@example.test']);
        $this->getJson("/api/automations/{$automation->id}/preview")->assertOk()->assertJsonPath('data.0.status', 'ready');
        $this->postJson("/api/automations/{$automation->id}/run")->assertOk()->assertJsonPath('data.sent', 1)->assertJsonPath('data.trigger', 'manual');
    }

    public function test_command_runs_only_enabled_automations_that_are_due(): void
    {
        // No sender chosen: the scheduler sends as the first admin.
        \App\Models\User::factory()->create(['role' => 'admin']);
        $automation = $this->automation(Automation::BIRTHDAY, 'Hi', 'Happy birthday!');
        Client::factory()->born(now()->subYears(30)->toDateString())->create(['email' => 'ana@example.test']);
        Automation::where('key', Automation::PREMIUM_DUE)->update(['enabled' => false]);

        $this->artisan('crm:automations')->assertSuccessful();
        $this->assertCount(1, $this->sent());
        $this->assertNotNull($automation->refresh()->last_run_at);

        // Already ran today: a second scheduled pass sends nothing.
        $this->artisan('crm:automations')->assertSuccessful();
        $this->assertCount(1, $this->sent());
    }
}

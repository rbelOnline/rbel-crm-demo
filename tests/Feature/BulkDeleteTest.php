<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Policy;
use App\Models\Beneficiary;
use Tests\TestCase;

class BulkDeleteTest extends TestCase
{
    public function test_leads_bulk_delete(): void
    {
        $this->signIn('advisor');
        $leads = Lead::factory()->count(2)->create();
        $kept = Lead::factory()->create();

        $response = $this->postJson('/api/leads/bulk-delete', ['ids' => [...$leads->pluck('id'), 999999]])
            ->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonCount(1, 'skipped');

        $leads->each(fn ($l) => $this->assertModelMissing($l));
        $this->assertModelExists($kept);
        $this->assertStringContainsString('Not found', $response->json('skipped.0.reason'));

        // Each removal is audited individually.
        $this->assertSame(2, AuditLog::where(['module' => 'leads', 'action' => 'deleted'])->count());
    }

    public function test_clients_bulk_delete_takes_their_policies_along(): void
    {
        $this->signIn('advisor');
        ['juan' => $juan, 'maria' => $maria, 'a' => $a, 'b' => $b, 'c' => $c] = $this->ownerInsuredScenario();
        $loner = Client::factory()->create();

        $this->postJson('/api/clients/bulk-delete', ['ids' => [$juan->id, $loner->id]])
            ->assertOk()->assertJsonPath('deleted', 2)->assertJsonPath('skipped', []);

        $this->assertModelMissing($juan);
        $this->assertModelMissing($loner);
        // Juan owned A and was insured under C; Maria's own policy B stays.
        $this->assertModelMissing($a);
        $this->assertModelMissing($c);
        $this->assertModelExists($b);
        $this->assertModelExists($maria);
    }

    public function test_clients_bulk_delete_removes_policies_with_their_beneficiaries(): void
    {
        $this->signIn('admin');
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->ownerInsuredScenario();
        $beneficiary = Beneficiary::factory()->create(['policy_id' => $a->id]);

        $this->postJson('/api/policies/bulk-delete', ['ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonPath('deleted', 2)->assertJsonPath('skipped', []);

        $this->assertModelMissing($a);
        $this->assertModelMissing($b);
        $this->assertModelMissing($beneficiary);
        $this->assertModelExists($c);
    }

    public function test_deleting_a_client_record_deletes_its_beneficiaries_but_not_the_clients(): void
    {
        $this->signIn('admin');
        ['a' => $a, 'b' => $b, 'juan' => $juan, 'maria' => $maria] = $this->ownerInsuredScenario();
        $onA = Beneficiary::factory()->create(['policy_id' => $a->id]);
        $onB = Beneficiary::factory()->create(['policy_id' => $b->id]);

        $this->deleteJson("/api/policies/{$a->id}")->assertNoContent();

        $this->assertModelMissing($a);
        $this->assertModelMissing($onA);
        $this->assertModelExists($onB);
        // Owners / insureds are never removed by deleting a policy.
        $this->assertModelExists($juan);
        $this->assertModelExists($maria);
        $this->assertTrue(AuditLog::where(['module' => 'beneficiaries', 'action' => 'deleted', 'record_id' => $onA->id])->exists());
    }

    public function test_appointments_and_email_templates_bulk_delete(): void
    {
        $this->signIn();
        $appointments = Appointment::factory()->count(3)->create();
        $templates = EmailTemplate::factory()->count(2)->create();

        $this->postJson('/api/appointments/bulk-delete', ['ids' => $appointments->take(2)->pluck('id')->all()])
            ->assertOk()->assertJsonPath('deleted', 2);
        $this->assertSame(1, Appointment::count());

        $this->postJson('/api/email-templates/bulk-delete', ['ids' => $templates->pluck('id')->all()])
            ->assertOk()->assertJsonPath('deleted', 2);
        $this->assertSame(0, EmailTemplate::count());
    }

    public function test_bulk_delete_validation_and_authorization(): void
    {
        $this->signIn();
        $this->postJson('/api/appointments/bulk-delete', ['ids' => []])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->postJson('/api/appointments/bulk-delete', ['ids' => [1, 1]])->assertUnprocessable()->assertJsonValidationErrors('ids.1');
        $this->postJson('/api/appointments/bulk-delete', ['ids' => range(1, 101)])->assertUnprocessable()->assertJsonValidationErrors('ids');

        // Assistants cannot delete, singly or in bulk.
        $this->signIn('assistant');
        $appointment = Appointment::factory()->create();
        foreach (['clients', 'policies', 'appointments', 'email-templates'] as $module) {
            $this->postJson("/api/{$module}/bulk-delete", ['ids' => [$appointment->id]])->assertForbidden();
        }
        $this->assertModelExists($appointment);
    }
}

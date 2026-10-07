<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Lead;
use Tests\TestCase;

/** Leads are their own table, separate from clients, until converted. */
class LeadTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Lara',
            'middle_name' => 'Cruz',
            'last_name' => 'Aquino',
            'occupation' => 'Architect',
            'birthdate' => '1990-01-01',
            'gender' => 'female',
            'email' => 'lara@example.test',
            'mobile_number' => '+63 917 000 1111',
            'notes' => 'Met at a seminar.',
        ], $overrides);
    }

    public function test_create_list_update_and_delete_a_lead(): void
    {
        $this->signIn('admin');

        $id = $this->postJson('/api/leads', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.notes', 'Met at a seminar.')
            ->assertJsonMissingPath('data.full_name')
            ->json('data.id');

        // Leads are not clients.
        $this->assertSame(0, Client::count());
        $this->getJson('/api/leads?search=Lara%20Aquino')->assertOk()->assertJsonPath('data.0.id', $id);

        $this->putJson("/api/leads/{$id}", $this->payload(['notes' => 'Follow up in May.']))->assertOk()->assertJsonPath('data.notes', 'Follow up in May.');

        $this->deleteJson("/api/leads/{$id}")->assertNoContent();
        $this->assertSame(0, Lead::count());
        $this->assertTrue(AuditLog::where('module', 'leads')->where('action', 'deleted')->where('record_id', $id)->exists());
    }

    public function test_lead_validation(): void
    {
        $this->signIn();

        $this->postJson('/api/leads', $this->payload(['first_name' => 'R2D2', 'last_name' => '', 'email' => 'x']))
            ->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'email']);
    }

    public function test_converting_a_lead_creates_a_client_and_removes_the_lead(): void
    {
        $this->signIn();
        $lead = Lead::factory()->create($this->payload());

        $clientId = $this->postJson("/api/leads/{$lead->id}/convert", ['address' => '1 Rizal St.', 'is_policy_owner' => true])
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Lara')
            ->assertJsonPath('data.occupation', 'Architect')
            ->assertJsonPath('data.address', '1 Rizal St.')
            ->assertJsonPath('data.is_policy_owner', true)
            ->json('data.id');

        $this->assertModelMissing($lead);
        $client = Client::findOrFail($clientId);
        $this->assertSame('lara@example.test', $client->email);
        $this->assertSame('1990-01-01', $client->birthdate->toDateString());
        $this->assertTrue(AuditLog::where('module', 'leads')->where('action', 'converted')->where('record_id', $lead->id)->exists());
    }

    public function test_assistant_cannot_delete_a_lead(): void
    {
        $this->signIn('assistant');
        $lead = Lead::factory()->create();

        $this->deleteJson("/api/leads/{$lead->id}")->assertForbidden();
        $this->assertModelExists($lead);
    }
}

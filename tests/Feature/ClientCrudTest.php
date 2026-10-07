<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Beneficiary;
use App\Models\Client;
use App\Models\Reminder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientCrudTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Carmela',
            'middle_name' => 'Reyes',
            'last_name' => 'Santos',
            'occupation' => 'Nurse',
            'birthdate' => '1988-07-04',
            'gender' => 'female',
            'email' => 'carmela.santos@example.test',
            'mobile_number' => '+63 917 123 4567',
            'address' => '12 Mabini St., Pasig',
            'is_policy_owner' => true,
        ], $overrides);
    }

    public function test_clients_table_stores_name_parts_only(): void
    {
        // The SPA joins names and computes birthdays; nothing derived is stored.
        $this->assertFalse(Schema::hasColumn('clients', 'full_name'));
        $this->assertFalse(Schema::hasColumn('clients', 'birth_md'));
        $this->assertFalse(Schema::hasTable('persons'));
    }

    public function test_create_view_update_client(): void
    {
        $this->signIn();

        $id = $this->postJson('/api/clients', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Carmela')
            ->assertJsonPath('data.middle_name', 'Reyes')
            ->assertJsonPath('data.is_policy_owner', true)
            ->assertJsonPath('data.address', '12 Mabini St., Pasig')
            ->assertJsonMissingPath('data.full_name')
            ->json('data.id');

        $this->getJson("/api/clients/{$id}")->assertOk()->assertJsonPath('data.email', 'carmela.santos@example.test');

        $this->putJson("/api/clients/{$id}", $this->payload(['last_name' => 'Garcia']))
            ->assertOk()->assertJsonPath('data.last_name', 'Garcia');

        $log = AuditLog::where('module', 'clients')->where('action', 'updated')->where('record_id', $id)->firstOrFail();
        $this->assertSame(['last_name' => 'Santos'], $log->old_values);
        $this->assertSame(['last_name' => 'Garcia'], $log->new_values);
    }

    public function test_client_validation(): void
    {
        $this->signIn();

        $this->postJson('/api/clients', $this->payload([
            'first_name' => '', 'email' => 'nope', 'birthdate' => now()->addDay()->toDateString(), 'mobile_number' => 'abc', 'gender' => 'x', 'is_policy_owner' => 'maybe',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'email', 'birthdate', 'mobile_number', 'gender', 'is_policy_owner']);
    }

    public function test_a_client_who_owns_policies_stays_a_policy_owner(): void
    {
        $this->signIn();
        ['juan' => $juan] = $this->ownerInsuredScenario();

        $this->putJson("/api/clients/{$juan->id}", $this->payload(['is_policy_owner' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('is_policy_owner');
    }

    public function test_delete_client(): void
    {
        $this->signIn('admin');
        $client = Client::factory()->create();

        $this->deleteJson("/api/clients/{$client->id}")->assertNoContent();
        $this->assertModelMissing($client);
        $this->assertTrue(AuditLog::where('module', 'clients')->where('action', 'deleted')->where('record_id', $client->id)->exists());
    }

    public function test_deleting_a_client_deletes_their_policies_and_related_records(): void
    {
        $this->signIn('admin');
        ['juan' => $juan, 'maria' => $maria, 'pedro' => $pedro, 'a' => $a, 'b' => $b, 'c' => $c] = $this->ownerInsuredScenario();
        Beneficiary::factory()->create(['policy_id' => $a->id]);
        $appointment = Appointment::factory()->create(['client_id' => $juan->id]);
        $reminder = Reminder::create(['client_id' => $juan->id, 'title' => 'Call', 'due_date' => today()]);

        // Juan owns A (Maria insured) and is insured under C (Pedro owns).
        $this->deleteJson("/api/clients/{$juan->id}")->assertNoContent();

        $this->assertModelMissing($juan);
        $this->assertModelMissing($a);
        $this->assertModelMissing($c);
        $this->assertModelMissing($appointment);
        $this->assertModelMissing($reminder);
        $this->assertSame(0, Beneficiary::where('policy_id', $a->id)->count());
        // Other people and their own policies are untouched.
        $this->assertModelExists($maria);
        $this->assertModelExists($pedro);
        $this->assertModelExists($b);
        $this->assertTrue(AuditLog::where('module', 'policies')->where('action', 'deleted')->where('record_id', $c->id)->exists());
    }

    public function test_assistant_cannot_delete(): void
    {
        $this->signIn('assistant');
        $client = Client::factory()->create();

        $this->deleteJson("/api/clients/{$client->id}")->assertForbidden();
        $this->assertModelExists($client);
    }

    public function test_list_search_sort_paginate_and_role_filters(): void
    {
        $this->signIn();
        ['juan' => $juan, 'maria' => $maria, 'pedro' => $pedro] = $this->ownerInsuredScenario();
        Client::factory()->count(3)->create();
        $insuredOnly = Client::factory()->insuredOnly()->create(['first_name' => 'Kiddo']);

        $this->getJson('/api/clients?per_page=2')->assertOk()->assertJsonPath('meta.total', 7)->assertJsonCount(2, 'data');
        $this->getJson('/api/clients?search=Kiddo')->assertOk()->assertJsonPath('data.0.id', $insuredOnly->id);
        $this->getJson('/api/clients?search=Zamora')->assertOk()->assertJsonPath('data.0.id', $pedro->id);
        // A name typed in full, across first and last name.
        $this->getJson('/api/clients?search=Pedro%20Zamora')->assertOk()->assertJsonPath('data.0.id', $pedro->id);

        $this->assertSame([$insuredOnly->id], collect($this->getJson('/api/clients?is_policy_owner=0')->json('data'))->pluck('id')->all());

        // Pedro owns but is not insured; Maria is both; Juan is both.
        $this->assertSame([$pedro->id], collect($this->getJson('/api/clients?role=owner_only')->json('data'))->pluck('id')->all());

        $owners = collect($this->getJson('/api/clients?role=owner')->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$juan->id, $maria->id, $pedro->id])->sort()->values()->all(), $owners);

        $top = $this->getJson('/api/clients?sort=total_ape&direction=desc')->json('data.0');
        $this->assertSame($pedro->id, $top['id']);
        $this->assertSame(40000.0, (float) $top['total_ape_owned']);
    }

    public function test_birth_month_filter(): void
    {
        $this->signIn();
        $march = Client::factory()->born('1990-03-15')->create();
        Client::factory()->born('1990-04-15')->create();

        $this->assertSame([$march->id], collect($this->getJson('/api/clients?birth_month=3')->json('data'))->pluck('id')->all());
    }

    public function test_client_lookup_by_explicit_role(): void
    {
        $this->signIn();
        ['juan' => $juan, 'maria' => $maria, 'pedro' => $pedro] = $this->ownerInsuredScenario();
        $insuredOnly = Client::factory()->insuredOnly()->create();
        $ownerNoPolicy = Client::factory()->create();

        $lookup = fn (string $qs) => collect($this->getJson("/api/clients/lookup?{$qs}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        // May own a policy (flag), whether or not they own one yet.
        $this->assertSame(collect([$juan->id, $maria->id, $pedro->id, $ownerNoPolicy->id])->sort()->values()->all(), $lookup('role=owner'));
        // Actually own / are insured under a policy. Pedro owns but is insured under nothing.
        $this->assertSame(collect([$juan->id, $maria->id, $pedro->id])->sort()->values()->all(), $lookup('role=owns'));
        $this->assertSame(collect([$juan->id, $maria->id])->sort()->values()->all(), $lookup('role=insured'));
        $this->assertContains($insuredOnly->id, $lookup(''));

        $this->getJson('/api/clients/lookup?role=bogus')->assertUnprocessable()->assertJsonValidationErrors('role');
    }
}

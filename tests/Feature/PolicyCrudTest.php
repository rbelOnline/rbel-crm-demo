<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Policy;
use App\Models\Beneficiary;
use Tests\TestCase;

class PolicyCrudTest extends TestCase
{
    /** The details every Policy Owner / Insured must have, for typed (new) people. */
    private function contact(array $overrides = []): array
    {
        return $overrides + ['birthdate' => '1990-05-20', 'email' => 'person@example.test', 'mobile_number' => '+63 917 555 0000', 'address' => '1 Mabini St., Manila'];
    }

    private function payload(Client $owner, Client $insured, array $overrides = []): array
    {
        return array_merge([
            'policy_number' => 'rb-2026-777001',
            'policy_owner_id' => $owner->id,
            'policy_insured_id' => $insured->id,
            'product_id' => $this->product()->id,
            'ape' => 48000,
            'issued_date' => now()->subMonth()->toDateString(),
            'mode_of_payment' => 'monthly',
            'sum_assured' => 2000000,
            'status' => 'active',
            'policy_delivery_date' => null,
            'is_orphan' => false,
        ], $overrides);
    }

    public function test_create_policy_with_different_owner_and_insured_and_beneficiaries(): void
    {
        $this->signIn();
        $owner = Client::factory()->create(['first_name' => 'Juan']);
        $insured = Client::factory()->insuredOnly()->create(['first_name' => 'Maria']);
        $clients = Client::count();

        $response = $this->postJson('/api/policies', $this->payload($owner, $insured, [
            'beneficiaries' => [
                ['first_name' => 'Rico', 'last_name' => 'Santos', 'relationship' => 'sibling', 'beneficiary_type' => 'primary', 'allocation_percentage' => 60],
                ['first_name' => 'Nina', 'middle_name' => 'Reyes', 'last_name' => 'Dela Cruz', 'birthdate' => '2015-02-01', 'relationship' => 'child', 'beneficiary_type' => 'primary', 'allocation_percentage' => 40],
            ],
        ]))->assertCreated();

        $response->assertJsonPath('data.policy_number', 'RB-2026-777001')
            ->assertJsonPath('data.policy_owner.id', $owner->id)
            ->assertJsonPath('data.policy_insured.id', $insured->id)
            ->assertJsonPath('data.is_self_insured', false)
            ->assertJsonCount(2, 'data.beneficiaries');

        // Beneficiaries are stored with their own details, not as clients.
        $this->assertSame($clients, Client::count());
        $this->assertSame('Reyes', Beneficiary::where('first_name', 'Nina')->firstOrFail()->middle_name);
    }

    public function test_owner_lookup_finds_policy_owners_and_leads(): void
    {
        $this->signIn();
        $owner = Client::factory()->create(['first_name' => 'Marco', 'last_name' => 'Reyes']);
        Client::factory()->insuredOnly()->create(['first_name' => 'Marco', 'last_name' => 'Junior']);
        $lead = Lead::factory()->create(['first_name' => 'Marco', 'last_name' => 'Lim']);

        $found = collect($this->getJson('/api/owner-lookup?q=Marco')->assertOk()->json('data'))->map(fn ($r) => "{$r['kind']}:{$r['id']}")->sort()->values()->all();
        $this->assertSame(["client:{$owner->id}", "lead:{$lead->id}"], $found);

        $this->getJson("/api/owner-lookup?lead_id={$lead->id}")->assertOk()->assertJsonPath('data.0.kind', 'lead')->assertJsonCount(1, 'data');
    }

    public function test_a_lead_picked_as_owner_becomes_a_client_when_the_policy_is_saved(): void
    {
        $this->signIn();
        $insured = Client::factory()->insuredOnly()->create();
        $lead = Lead::factory()->create(['first_name' => 'Lara', 'last_name' => 'Aquino', 'occupation' => 'Architect', 'birthdate' => '1990-01-01']);
        $base = collect($this->payload($insured, $insured))->except(['policy_owner_id'])->all();

        // A lead has no address on record, so it must be sent.
        $this->postJson('/api/policies', $base + ['policy_owner_lead_id' => $lead->id, 'policy_owner' => $this->contact(['address' => null])])
            ->assertUnprocessable()->assertJsonValidationErrors('policy_owner.address');

        $data = $this->postJson('/api/policies', $base + [
            'policy_owner_lead_id' => $lead->id,
            'policy_owner' => ['first_name' => 'Lara', 'last_name' => 'Aquino-Cruz'] + $this->contact(['birthdate' => '1990-01-01', 'address' => '9 Luna St.']),
        ])->assertCreated()->json('data');

        $this->assertModelMissing($lead);
        $owner = Client::findOrFail($data['policy_owner']['id']);
        $this->assertTrue($owner->is_policy_owner);
        $this->assertSame(['Lara', 'Aquino-Cruz', 'Architect', '9 Luna St.'], [$owner->first_name, $owner->last_name, $owner->occupation, $owner->address]);
        $this->assertSame($insured->id, $data['policy_insured']['id']);

        // A lead and an existing owner cannot both be sent.
        $other = Lead::factory()->create();
        $this->postJson('/api/policies', $base + ['policy_number' => 'RB-2026-777050', 'policy_owner_id' => $owner->id, 'policy_owner_lead_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('policy_owner_lead_id');
    }

    public function test_a_lead_owner_can_also_be_the_insured(): void
    {
        $this->signIn();
        $lead = Lead::factory()->create(['birthdate' => '1985-05-05']);
        $base = collect($this->payload(Client::factory()->create(), Client::factory()->create()))->except(['policy_owner_id', 'policy_insured_id'])->all();

        $data = $this->postJson('/api/policies', $base + [
            'policy_owner_lead_id' => $lead->id,
            'policy_owner' => $this->contact(['birthdate' => '1985-05-05']),
            'insured_same_as_owner' => true,
        ])->assertCreated()->assertJsonPath('data.is_self_insured', true)->json('data');

        $this->assertSame($data['policy_owner']['id'], $data['policy_insured']['id']);
        $this->assertModelMissing($lead);
    }

    public function test_only_clients_flagged_as_policy_owner_may_own_a_policy(): void
    {
        $this->signIn();
        $owner = Client::factory()->create();
        $child = Client::factory()->insuredOnly()->create();

        // An insured-only client cannot be the owner...
        $this->postJson('/api/policies', $this->payload($child, $owner))
            ->assertUnprocessable()->assertJsonValidationErrors('policy_owner_id');

        // ...but can be the insured.
        $this->postJson('/api/policies', $this->payload($owner, $child))->assertCreated();

        // A typed insured is created insured-only; a typed owner may own policies.
        $base = collect($this->payload($owner, $owner, ['policy_number' => 'RB-2026-777099']))->except(['policy_owner_id', 'policy_insured_id'])->all();
        $data = $this->postJson('/api/policies', $base + [
            'policy_owner' => ['first_name' => 'Rosa', 'last_name' => 'Villanueva'] + $this->contact(),
            'policy_insured' => ['first_name' => 'Ben', 'last_name' => 'Villanueva'] + $this->contact(),
        ])->assertCreated()->json('data');
        $this->assertTrue(Client::find($data['policy_owner']['id'])->is_policy_owner);
        $this->assertFalse(Client::find($data['policy_insured']['id'])->is_policy_owner);
    }

    public function test_policy_owner_must_be_at_least_18_on_the_issued_date(): void
    {
        $this->signIn();
        $issued = now()->subMonth();
        $adult = Client::factory()->born($issued->copy()->subYears(18)->toDateString())->create(); // 18 exactly on the issued date
        $minor = Client::factory()->born($issued->copy()->subYears(18)->addDay()->toDateString())->create(); // one day short
        $child = Client::factory()->born(now()->subYears(5)->toDateString())->create();
        $message = 'The Policy Owner must be at least 18 years old on the issued date.';

        // Existing person as owner, using the birthdate on record.
        $this->postJson('/api/policies', $this->payload($minor, $adult))
            ->assertUnprocessable()->assertJsonValidationErrors(['policy_owner.birthdate' => $message]);

        // The insured may be a minor (e.g. a parent insuring a child).
        $id = $this->postJson('/api/policies', $this->payload($adult, $child))->assertCreated()->json('data.id');

        // A birthdate sent with the request is checked, and editing applies the same rule.
        $this->putJson("/api/policies/{$id}", $this->payload($adult, $child, ['policy_owner' => ['birthdate' => now()->subYears(17)->toDateString()]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['policy_owner.birthdate' => $message]);

        // A typed (new) owner, also when they are the insured.
        $base = collect($this->payload($adult, $adult, ['policy_number' => 'RB-2026-777010']))->except(['policy_owner_id', 'policy_insured_id'])->all();
        $this->postJson('/api/policies', $base + [
            'policy_owner' => ['first_name' => 'Teen', 'last_name' => 'Santos'] + $this->contact(['birthdate' => now()->subYears(16)->toDateString()]),
            'insured_same_as_owner' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['policy_owner.birthdate' => $message]);
    }

    public function test_create_client_from_typed_owner_and_insured_names(): void
    {
        $this->signIn();
        // A person with the same name already exists: typed names always create a new person.
        $namesake = Client::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Villanueva']);
        $base = collect($this->payload($namesake, $namesake))->except(['policy_owner_id', 'policy_insured_id'])->all();

        $data = $this->postJson('/api/policies', $base + [
            'policy_owner' => ['first_name' => 'Rosa', 'last_name' => 'Villanueva'] + $this->contact(),
            'policy_insured' => ['first_name' => 'Ben', 'last_name' => 'Dela Cruz'] + $this->contact(),
        ])->assertCreated()->assertJsonPath('data.is_self_insured', false)->json('data');

        $owner = Client::findOrFail($data['policy_owner']['id']);
        $insured = Client::findOrFail($data['policy_insured']['id']);
        $this->assertNotSame($namesake->id, $owner->id);
        $this->assertSame(['Rosa', 'Villanueva', true], [$owner->first_name, $owner->last_name, $owner->is_policy_owner]);
        $this->assertSame(['Ben', 'Dela Cruz', false], [$insured->first_name, $insured->last_name, $insured->is_policy_owner]);

        // "Owner is also the insured" links both roles to the one new person.
        $self = $this->postJson('/api/policies', array_merge($base, ['policy_number' => 'RB-2026-777002']) + [
            'policy_owner' => ['first_name' => 'Tess', 'last_name' => 'Ramos'] + $this->contact(),
            'insured_same_as_owner' => true,
        ])->assertCreated()->assertJsonPath('data.is_self_insured', true)->json('data');
        $this->assertSame($self['policy_owner']['id'], $self['policy_insured']['id']);

        // Names are required when no existing person is given.
        $this->postJson('/api/policies', array_merge($base, ['policy_number' => 'RB-2026-777003']) + [
            'policy_owner' => ['first_name' => '', 'last_name' => ''],
        ])->assertUnprocessable()->assertJsonValidationErrors(['policy_owner.first_name', 'policy_owner.last_name', 'policy_insured.first_name', 'policy_insured.last_name']);
    }

    public function test_owner_and_insured_contact_details_are_saved_on_create_and_edit(): void
    {
        $this->signIn();
        $namesake = Client::factory()->create();
        $base = collect($this->payload($namesake, $namesake))->except(['policy_owner_id', 'policy_insured_id'])->all();

        // New client: contact details seed the newly created people.
        $data = $this->postJson('/api/policies', $base + [
            'policy_owner' => ['first_name' => 'Rosa', 'last_name' => 'Villanueva', 'birthdate' => '1984-03-15', 'gender' => 'female', 'email' => 'rosa@example.test', 'mobile_number' => '+63 917 555 0101', 'address' => '5 Rizal Ave., Makati', 'occupation' => 'Engineer'],
            'policy_insured' => ['first_name' => 'Ben', 'last_name' => 'Villanueva', 'occupation' => 'Student'] + $this->contact(),
        ])->assertCreated()->json('data');

        $owner = Client::findOrFail($data['policy_owner']['id']);
        $this->assertSame(['rosa@example.test', '+63 917 555 0101', '5 Rizal Ave., Makati', 'Engineer'], [$owner->email, $owner->mobile_number, $owner->address, $owner->occupation]);
        $this->assertSame('1984-03-15', $owner->birthdate->toDateString());
        $this->assertSame('female', $owner->gender);
        $this->assertSame('Student', Client::findOrFail($data['policy_insured']['id'])->occupation);

        // Edit: details sent with an existing person's id update that person (and are audited).
        $this->putJson("/api/policies/{$data['id']}", array_merge($base, [
            'policy_owner_id' => $owner->id,
            'policy_owner' => ['birthdate' => '1984-03-16', 'gender' => 'other', 'email' => 'rosa.v@example.test', 'mobile_number' => '0917 555 0199', 'address' => '9 Ayala Ave., Makati', 'occupation' => 'Architect'],
            'policy_insured_id' => $data['policy_insured']['id'],
        ]))->assertOk();

        $owner->refresh();
        $this->assertSame(['rosa.v@example.test', '0917 555 0199', '9 Ayala Ave., Makati', 'Architect'], [$owner->email, $owner->mobile_number, $owner->address, $owner->occupation]);
        $this->assertSame('1984-03-16', $owner->birthdate->toDateString());
        $this->assertSame('other', $owner->gender);
        $this->assertTrue(\App\Models\AuditLog::where(['module' => 'clients', 'action' => 'updated', 'record_id' => $owner->id])->exists());

        // Contact details are validated.
        $this->postJson('/api/policies', array_merge($base, ['policy_number' => 'RB-2026-777009']) + [
            'policy_owner' => ['first_name' => 'X', 'last_name' => 'Y', 'birthdate' => now()->addDay()->toDateString(), 'gender' => 'x', 'email' => 'not-an-email', 'mobile_number' => 'abc'],
            'insured_same_as_owner' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['policy_owner.birthdate', 'policy_owner.gender', 'policy_owner.email', 'policy_owner.mobile_number']);
    }

    public function test_owner_and_insured_names_take_a_middle_name_and_reject_numbers(): void
    {
        $this->signIn();
        $namesake = Client::factory()->create();
        $base = collect($this->payload($namesake, $namesake))->except(['policy_owner_id', 'policy_insured_id'])->all();

        // Typed names (with letters from any language, periods, apostrophes, hyphens) create the person with a middle name.
        $data = $this->postJson('/api/policies', $base + [
            'policy_owner' => ['first_name' => 'Ma. Cristina', 'middle_name' => 'Peña', 'last_name' => "O'Neil-Santos"] + $this->contact(),
            'insured_same_as_owner' => true,
        ])->assertCreated()->json('data');
        $owner = Client::findOrFail($data['policy_owner']['id']);
        $this->assertSame(['Ma. Cristina', 'Peña', "O'Neil-Santos"], [$owner->first_name, $owner->middle_name, $owner->last_name]);

        // Editing: names sent with a linked person update that person; an empty middle name clears it.
        $this->putJson("/api/policies/{$data['id']}", array_merge($base, [
            'policy_owner_id' => $owner->id,
            'policy_owner' => ['first_name' => 'Maria', 'middle_name' => '', 'last_name' => 'Santos'],
            'insured_same_as_owner' => true,
        ]))->assertOk();
        $owner->refresh();
        $this->assertSame(['Maria', null, 'Santos'], [$owner->first_name, $owner->middle_name, $owner->last_name]);

        // Numbers (and other symbols) are rejected in all three names, for owner and insured.
        $this->postJson('/api/policies', array_merge($base, ['policy_number' => 'RB-2026-777010']) + [
            'policy_owner' => ['first_name' => 'Juan2', 'middle_name' => '3rd', 'last_name' => 'Cruz'] + $this->contact(),
            'policy_insured' => ['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Re@yes'] + $this->contact(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['policy_owner.first_name', 'policy_owner.middle_name', 'policy_insured.last_name'])
            ->assertJson(['errors' => ['policy_owner.first_name' => ['The owner first name cannot contain numbers.']]]);

        // Linked people are renamed with the same rules.
        $this->putJson("/api/policies/{$data['id']}", array_merge($base, ['policy_owner_id' => $owner->id, 'policy_owner' => ['last_name' => 'Santos 2'], 'insured_same_as_owner' => true]))
            ->assertJsonValidationErrors('policy_owner.last_name');
    }

    public function test_owner_and_insured_must_have_mobile_email_birthdate_address_and_names(): void
    {
        $this->signIn();
        $namesake = Client::factory()->create();
        $base = collect($this->payload($namesake, $namesake))->except(['policy_owner_id', 'policy_insured_id'])->all();
        $required = ['first_name', 'last_name', 'birthdate', 'email', 'mobile_number', 'address'];

        // New people: every required detail must be given, for owner and insured.
        $res = $this->postJson('/api/policies', $base + ['policy_owner' => ['first_name' => '', 'last_name' => ''], 'policy_insured' => ['first_name' => 'Ben', 'last_name' => 'Cruz']])->assertUnprocessable();
        $res->assertJsonValidationErrors(array_map(fn ($f) => "policy_owner.$f", $required));
        $res->assertJsonValidationErrors(['policy_insured.birthdate', 'policy_insured.email', 'policy_insured.mobile_number', 'policy_insured.address']);
        $res->assertJson(['errors' => ['policy_owner.mobile_number' => ['Owner mobile number is required.']]]);

        // Insured details aren't needed when the owner is the insured.
        $this->postJson('/api/policies', $base + ['policy_owner' => ['first_name' => 'Ana', 'last_name' => 'Cruz'] + $this->contact(), 'insured_same_as_owner' => true])->assertCreated();

        // Linked person: a detail missing on record must be filled in, and can't be cleared.
        $incomplete = Client::factory()->create(['address' => null, 'mobile_number' => null]);
        $this->postJson('/api/policies', array_merge($base, ['policy_number' => 'RB-2026-777011', 'policy_owner_id' => $incomplete->id, 'insured_same_as_owner' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors(['policy_owner.address', 'policy_owner.mobile_number'])->assertJsonMissingValidationErrors(['policy_owner.email']);
        $this->postJson('/api/policies', array_merge($base, ['policy_number' => 'RB-2026-777011', 'policy_owner_id' => $incomplete->id, 'insured_same_as_owner' => true, 'policy_owner' => ['address' => '2 Luna St.', 'mobile_number' => '0917 000 1111']]))
            ->assertCreated();
        $this->postJson('/api/policies', array_merge($base, ['policy_number' => 'RB-2026-777012', 'policy_owner_id' => $incomplete->id, 'insured_same_as_owner' => true, 'policy_owner' => ['email' => '']]))
            ->assertJsonValidationErrors('policy_owner.email');
    }

    public function test_policy_and_beneficiaries_are_created_atomically(): void
    {
        $this->signIn();
        $owner = Client::factory()->create();

        // Force the second step (beneficiary insert) to fail.
        Beneficiary::creating(fn () => throw new \RuntimeException('boom'));

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/policies', $this->payload($owner, $owner, [
                'beneficiaries' => [['first_name' => 'A', 'last_name' => 'B', 'relationship' => 'child', 'allocation_percentage' => 100]],
            ]));
            $this->fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseMissing('policies', ['policy_number' => 'RB-2026-777001']);
        $this->assertDatabaseMissing('beneficiaries', ['first_name' => 'A', 'last_name' => 'B']);
    }

    public function test_policy_validation_rules(): void
    {
        $this->signIn();
        $owner = Client::factory()->create();
        Policy::factory()->selfInsured($owner)->create(['policy_number' => 'RB-DUP-1']);

        $this->postJson('/api/policies', $this->payload($owner, $owner, [
            'policy_number' => 'rb-dup-1',
            'ape' => -5,
            'sum_assured' => 0,
            'issued_date' => now()->addDay()->toDateString(),
            'mode_of_payment' => 'weekly',
            'policy_insured_id' => 999999,
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'policy_number', 'ape', 'sum_assured', 'issued_date', 'mode_of_payment', 'policy_insured_id',
        ]);

        $this->postJson('/api/policies', $this->payload($owner, $owner, [
            'issued_date' => '2025-06-10', 'policy_delivery_date' => '2025-06-01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('policy_delivery_date');
    }

    public function test_beneficiary_rules(): void
    {
        $this->signIn();
        $owner = Client::factory()->create();
        $insured = Client::factory()->create();
        $ben = fn (string $first, $pct) => ['first_name' => $first, 'last_name' => 'Cruz', 'relationship' => 'child', 'allocation_percentage' => $pct];

        // Every beneficiary needs a name.
        $this->postJson('/api/policies', $this->payload($owner, $insured, [
            'beneficiaries' => [['first_name' => '', 'last_name' => '', 'relationship' => 'spouse', 'allocation_percentage' => 100]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['beneficiaries.0.first_name', 'beneficiaries.0.last_name']);

        // Allocations over 100%.
        $this->postJson('/api/policies', $this->payload($owner, $insured, [
            'beneficiaries' => [$ben('Ana', 70), $ben('Ben', 50)],
        ]))->assertUnprocessable()->assertJsonValidationErrors('beneficiaries');

        // Fully allocated beneficiaries must total exactly 100%.
        $this->postJson('/api/policies', $this->payload($owner, $insured, [
            'beneficiaries' => [$ben('Ana', 90)],
        ]))->assertUnprocessable()->assertJsonValidationErrors('beneficiaries');
    }

    public function test_update_and_delete_policy(): void
    {
        $this->signIn('admin');
        ['a' => $a, 'maria' => $maria, 'pedro' => $pedro] = $this->ownerInsuredScenario();
        Beneficiary::factory()->create(['policy_id' => $a->id]);

        $data = $this->payload($pedro, $maria, ['policy_number' => $a->policy_number, 'status' => 'lapsed']);
        $this->putJson("/api/policies/{$a->id}", $data)
            ->assertOk()
            ->assertJsonPath('data.policy_owner.id', $pedro->id)
            ->assertJsonPath('data.status', 'lapsed')
            ->assertJsonCount(1, 'data.beneficiaries'); // untouched when not sent

        $this->assertNotNull($a->fresh()->status_changed_at);

        $this->deleteJson("/api/policies/{$a->id}")->assertNoContent();
        $this->assertModelMissing($a);
        $this->assertDatabaseCount('beneficiaries', 0);
    }

    public function test_assistant_can_edit_but_not_delete_policies(): void
    {
        $this->signIn('assistant');
        ['b' => $b] = $this->ownerInsuredScenario();

        $this->deleteJson("/api/policies/{$b->id}")->assertForbidden();
    }

    public function test_listing_shows_all_required_columns(): void
    {
        $this->signIn();
        $this->ownerInsuredScenario();

        $row = $this->getJson('/api/policies?sort=policy_number&direction=asc')->assertOk()->json('data.0');

        foreach (['policy_number', 'policy_owner', 'policy_insured', 'product', 'ape', 'issued_date', 'mode_of_payment', 'sum_assured', 'status', 'policy_delivery_date', 'is_orphan', 'beneficiaries_count'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertArrayNotHasKey('insurance_coverage_path', $row);
    }

    public function test_listing_filters(): void
    {
        $this->signIn();
        ['a' => $a] = $this->ownerInsuredScenario();
        $a->update(['is_orphan' => true, 'policy_delivery_date' => null, 'mode_of_payment' => 'quarterly']);
        Policy::factory()->create(['issued_date' => '2021-03-03', 'policy_delivery_date' => '2021-03-20', 'status' => 'lapsed', 'mode_of_payment' => 'annual']);

        $this->getJson('/api/policies?is_orphan=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/policies?delivery=pending')->assertJsonCount(1, 'data');
        $this->getJson('/api/policies?mode_of_payment=quarterly')->assertJsonCount(1, 'data');
        $this->getJson('/api/policies?year=2021')->assertJsonCount(1, 'data');
        $this->getJson('/api/policies?status=lapsed')->assertJsonCount(1, 'data');
        $this->getJson('/api/policies?search=RB-B')->assertJsonCount(1, 'data');
        $this->getJson('/api/policies?issued_from=2021-01-01&issued_to=2021-12-31')->assertJsonCount(1, 'data');
    }
}

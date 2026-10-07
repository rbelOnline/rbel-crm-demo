<?php

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\Client;
use App\Models\Policy;
use Tests\TestCase;

/** Beneficiaries are their own table: one row per designation, with the beneficiary's details. */
class BeneficiaryTest extends TestCase
{
    public function test_nested_beneficiary_crud(): void
    {
        $this->signIn();
        ['a' => $policy] = $this->ownerInsuredScenario();
        $clients = Client::count();

        $id = $this->postJson("/api/policies/{$policy->id}/beneficiaries", [
            'first_name' => 'Rosa', 'last_name' => 'Dela Cruz', 'relationship' => 'sibling', 'beneficiary_type' => 'primary', 'allocation_percentage' => 60,
        ])->assertCreated()->assertJsonPath('data.first_name', 'Rosa')->json('data.id');

        $this->postJson("/api/policies/{$policy->id}/beneficiaries", [
            'first_name' => 'New', 'last_name' => 'Beneficiary', 'email' => 'new.ben@example.test',
            'relationship' => 'child', 'allocation_percentage' => 40,
        ])->assertCreated()->assertJsonPath('data.email', 'new.ben@example.test');

        // Beneficiaries are not clients.
        $this->assertSame($clients, Client::count());
        $this->getJson("/api/policies/{$policy->id}/beneficiaries")->assertOk()->assertJsonCount(2, 'data');

        $this->putJson("/api/policies/{$policy->id}/beneficiaries/{$id}", [
            'first_name' => 'Rosa', 'last_name' => 'Dela Cruz', 'relationship' => 'parent', 'allocation_percentage' => 50,
        ])->assertOk()->assertJsonPath('data.relationship', 'parent');

        $this->deleteJson("/api/policies/{$policy->id}/beneficiaries/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('beneficiaries', ['id' => $id]);
    }

    public function test_editing_a_beneficiary_updates_their_own_details(): void
    {
        $this->signIn();
        ['a' => $policy] = $this->ownerInsuredScenario();

        $data = $this->postJson("/api/policies/{$policy->id}/beneficiaries", [
            'first_name' => 'Ana', 'middle_name' => 'Reyes', 'last_name' => 'Cruz', 'birthdate' => '2010-03-15',
            'relationship' => 'child', 'beneficiary_type' => 'contingent', 'allocation_percentage' => 100,
        ])->assertCreated()
            ->assertJsonPath('data.middle_name', 'Reyes')
            ->assertJsonPath('data.birthdate', '2010-03-15')
            ->json('data');
        Beneficiary::whereKey($data['id'])->update(['designation' => 'irrevocable']);

        $this->putJson("/api/policies/{$policy->id}/beneficiaries/{$data['id']}", [
            'first_name' => 'Anna', 'middle_name' => null, 'last_name' => 'Cruz', 'birthdate' => '2010-03-16',
            'relationship' => 'child', 'beneficiary_type' => 'primary', 'allocation_percentage' => 100,
        ])->assertOk()
            ->assertJsonPath('data.id', $data['id'])
            ->assertJsonPath('data.first_name', 'Anna')
            ->assertJsonPath('data.middle_name', null)
            ->assertJsonPath('data.beneficiary_type', 'primary')
            ->assertJsonPath('data.designation', 'irrevocable'); // not on the form: kept

        $this->assertSame(1, Beneficiary::count());
        $this->assertSame('2010-03-16', Beneficiary::find($data['id'])->birthdate->toDateString());
    }

    public function test_client_form_updates_existing_beneficiaries_in_place_and_says_secondary(): void
    {
        $this->signIn();
        ['a' => $policy] = $this->ownerInsuredScenario();
        $ben = $policy->beneficiaries()->create(['first_name' => 'Old', 'last_name' => 'Name', 'relationship' => 'child', 'beneficiary_type' => 'primary', 'designation' => 'irrevocable', 'allocation_percentage' => 100]);

        $payload = fn (array $beneficiaries) => [
            'policy_number' => $policy->policy_number, 'policy_owner_id' => $policy->policy_owner_id, 'policy_insured_id' => $policy->policy_insured_id,
            'product_id' => $policy->product_id, 'ape' => $policy->ape, 'sum_assured' => $policy->sum_assured, 'issued_date' => $policy->issued_date->toDateString(),
            'mode_of_payment' => $policy->mode_of_payment, 'status' => $policy->status, 'beneficiaries' => $beneficiaries,
        ];

        $this->putJson("/api/policies/{$policy->id}", $payload([
            ['id' => $ben->id, 'first_name' => 'New', 'middle_name' => 'M', 'last_name' => 'Name', 'birthdate' => '2015-01-01', 'relationship' => 'child', 'beneficiary_type' => 'primary', 'allocation_percentage' => 100],
        ]))->assertOk();

        $this->assertSame(['New', 'M', 'Name'], [$ben->fresh()->first_name, $ben->fresh()->middle_name, $ben->fresh()->last_name]);
        $this->assertSame('irrevocable', $ben->fresh()->designation);
        $this->assertSame(1, $policy->beneficiaries()->count());

        $this->putJson("/api/policies/{$policy->id}", $payload([
            ['first_name' => 'Sec', 'last_name' => 'One', 'relationship' => 'child', 'beneficiary_type' => 'contingent', 'allocation_percentage' => 60],
        ]))->assertUnprocessable()->assertJsonFragment(['Beneficiary percentages must total 100% (currently 60%).']);

        // A beneficiary left out of the list is removed.
        $this->putJson("/api/policies/{$policy->id}", $payload([
            ['first_name' => 'Replacement', 'last_name' => 'One', 'relationship' => 'child', 'allocation_percentage' => 100],
        ]))->assertOk();
        $this->assertModelMissing($ben);
        $this->assertSame(['Replacement'], $policy->beneficiaries()->pluck('first_name')->all());
    }

    public function test_all_beneficiaries_together_must_total_exactly_100_percent(): void
    {
        $this->signIn();
        ['a' => $policy] = $this->ownerInsuredScenario();
        $ben = fn (string $type, $pct) => ['first_name' => 'Ben', 'last_name' => ucfirst($type), 'relationship' => 'child', 'beneficiary_type' => $type, 'allocation_percentage' => $pct];
        $save = fn (array $beneficiaries) => $this->putJson("/api/policies/{$policy->id}", [
            'policy_number' => $policy->policy_number, 'policy_owner_id' => $policy->policy_owner_id, 'policy_insured_id' => $policy->policy_insured_id,
            'product_id' => $policy->product_id, 'ape' => $policy->ape, 'sum_assured' => $policy->sum_assured, 'issued_date' => $policy->issued_date->toDateString(),
            'mode_of_payment' => $policy->mode_of_payment, 'status' => $policy->status, 'beneficiaries' => $beneficiaries,
        ]);

        // Primary and Secondary count together: 100% each is 200% in total.
        $save([$ben('primary', 100), $ben('contingent', 100)])->assertUnprocessable()->assertJsonFragment(['Beneficiary percentages total 200%, which exceeds 100%.']);
        $save([$ben('primary', 33.33), $ben('primary', 33.33), $ben('contingent', 33.33)])->assertUnprocessable()->assertJsonFragment(['Beneficiary percentages must total 100% (currently 99.99%).']);
        // Every beneficiary needs a percentage.
        $save([$ben('primary', 100), $ben('contingent', null)])->assertUnprocessable()->assertJsonValidationErrors('beneficiaries.1.allocation_percentage');

        $save([$ben('primary', 60), $ben('contingent', 40)])->assertOk();
        $this->assertEquals(100, $policy->beneficiaries()->sum('allocation_percentage'));

        // No beneficiaries at all is still allowed.
        $save([])->assertOk();
    }

    public function test_beneficiary_of_another_policy_is_not_reachable(): void
    {
        $this->signIn();
        ['a' => $a, 'b' => $b] = $this->ownerInsuredScenario();
        $other = Beneficiary::factory()->create(['policy_id' => $b->id]);

        $this->putJson("/api/policies/{$a->id}/beneficiaries/{$other->id}", ['first_name' => 'X', 'last_name' => 'Y', 'relationship' => 'child', 'allocation_percentage' => 10])->assertNotFound();
        $this->deleteJson("/api/policies/{$a->id}/beneficiaries/{$other->id}")->assertNotFound();
    }

    public function test_allocation_cannot_exceed_100_and_names_are_required(): void
    {
        $this->signIn();
        ['a' => $a] = $this->ownerInsuredScenario();
        Beneficiary::factory()->create(['policy_id' => $a->id, 'allocation_percentage' => 80]);

        $this->postJson("/api/policies/{$a->id}/beneficiaries", [
            'first_name' => 'Too', 'last_name' => 'Much', 'relationship' => 'child', 'allocation_percentage' => 30,
        ])->assertUnprocessable()->assertJsonValidationErrors('allocation_percentage');

        $this->postJson("/api/policies/{$a->id}/beneficiaries", [
            'first_name' => '', 'last_name' => 'R2D2', 'relationship' => 'child', 'allocation_percentage' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    public function test_beneficiaries_are_deleted_with_their_policy(): void
    {
        $this->signIn('admin');
        $policy = Policy::factory()->create();
        Beneficiary::factory()->count(3)->create(['policy_id' => $policy->id, 'allocation_percentage' => null]);

        $this->assertSame(3, $policy->beneficiaries()->count());

        $this->deleteJson("/api/policies/{$policy->id}")->assertNoContent();
        $this->assertSame(0, Beneficiary::count());
    }
}

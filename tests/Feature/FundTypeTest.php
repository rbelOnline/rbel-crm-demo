<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FundType;
use App\Models\Policy;
use Tests\TestCase;

/** Fund Types module, and the Fund Type dropdown on client records (policies). */
class FundTypeTest extends TestCase
{
    private function policyPayload(Policy $policy, array $overrides = []): array
    {
        return array_merge([
            'policy_number' => $policy->policy_number, 'policy_owner_id' => $policy->policy_owner_id, 'policy_insured_id' => $policy->policy_insured_id,
            'product_id' => $policy->product_id, 'ape' => $policy->ape, 'sum_assured' => $policy->sum_assured,
            'issued_date' => $policy->issued_date->toDateString(), 'mode_of_payment' => $policy->mode_of_payment, 'status' => $policy->status,
        ], $overrides);
    }

    public function test_fund_type_crud_and_listing(): void
    {
        $this->signIn('admin');

        $id = $this->postJson('/api/fund-types', ['name' => '  Peso   Equity Fund ', 'suitability' => 'aggressive'])
            ->assertCreated()->assertJsonPath('data.name', 'Peso Equity Fund')->assertJsonPath('data.suitability', 'aggressive')->assertJsonPath('data.is_active', true)->json('data.id');

        $this->postJson('/api/fund-types', ['name' => 'Peso Equity Fund', 'suitability' => 'moderate'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/fund-types', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors(['name', 'suitability']);
        $this->postJson('/api/fund-types', ['name' => 'Bond Fund', 'suitability' => 'moderately_aggressive'])->assertUnprocessable()->assertJsonValidationErrors('suitability');

        $this->putJson("/api/fund-types/{$id}", ['name' => 'Peso Equity Fund', 'suitability' => 'moderate', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.suitability', 'moderate');
        $this->getJson('/api/fund-types?status=inactive')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/fund-types?suitability=moderate')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/fund-types?suitability=conservative')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/meta')->assertOk()
            ->assertJsonPath('data.fund_types.0.suitability', 'moderate')
            ->assertJsonPath('data.fund_suitabilities', ['conservative', 'moderate', 'aggressive']);

        $this->deleteJson("/api/fund-types/{$id}")->assertNoContent();
        $this->assertTrue(AuditLog::where(['module' => 'fund_types', 'action' => 'deleted', 'record_id' => $id])->exists());
    }

    public function test_assistant_can_view_but_not_manage(): void
    {
        $this->signIn('assistant');
        $fund = FundType::factory()->create();

        $this->getJson('/api/fund-types')->assertOk();
        $this->postJson('/api/fund-types', ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/fund-types/{$fund->id}")->assertForbidden();
    }

    public function test_a_policy_can_have_several_fund_types(): void
    {
        $this->signIn();
        $owner = Client::factory()->born('1975-01-01')->create();
        $balanced = FundType::factory()->create(['name' => 'Balanced Fund', 'suitability' => 'moderate']);
        $equity = FundType::factory()->create(['name' => 'Equity Fund', 'suitability' => 'aggressive']);
        $bond = FundType::factory()->create(['name' => 'Bond Fund', 'suitability' => 'conservative']);
        $policy = Policy::factory()->selfInsured($owner)->create();

        $this->putJson("/api/policies/{$policy->id}", $this->policyPayload($policy, ['fund_type_ids' => [$equity->id, $balanced->id]]))
            ->assertOk()
            ->assertJsonPath('data.fund_types.0.name', 'Balanced Fund') // listed by name
            ->assertJsonPath('data.fund_types.0.suitability', 'moderate')
            ->assertJsonPath('data.fund_types.1.name', 'Equity Fund')
            ->assertJsonCount(2, 'data.fund_type_ids');

        // Saving the list replaces it; the change is audited.
        $this->putJson("/api/policies/{$policy->id}", $this->policyPayload($policy, ['fund_type_ids' => [$bond->id, $balanced->id]]))
            ->assertOk()->assertJsonPath('data.fund_types.1.name', 'Bond Fund');
        $log = AuditLog::where(['module' => 'policies', 'record_id' => $policy->id])->whereNotNull('new_values->fund_type_ids')->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing([$balanced->id, $equity->id], $log->old_values['fund_type_ids']);
        $this->assertEqualsCanonicalizing([$bond->id, $balanced->id], $log->new_values['fund_type_ids']);

        // Left out of the payload: untouched. Empty list: cleared.
        $this->putJson("/api/policies/{$policy->id}", $this->policyPayload($policy))->assertOk()->assertJsonCount(2, 'data.fund_types');
        $this->putJson("/api/policies/{$policy->id}", $this->policyPayload($policy, ['fund_type_ids' => []]))->assertOk()->assertJsonCount(0, 'data.fund_types');

        // The same fund type twice is rejected.
        $this->putJson("/api/policies/{$policy->id}", $this->policyPayload($policy, ['fund_type_ids' => [$bond->id, $bond->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('fund_type_ids.0');
    }

    public function test_new_policy_is_created_with_its_fund_types(): void
    {
        $this->signIn();
        $owner = Client::factory()->born('1975-01-01')->create();
        $funds = FundType::factory()->count(2)->create();
        $policy = Policy::factory()->selfInsured($owner)->make();

        $id = $this->postJson('/api/policies', $this->policyPayload($policy, ['policy_number' => 'RB-FT-0001', 'fund_type_ids' => $funds->pluck('id')->all()] + [
            'policy_owner' => ['birthdate' => '1975-01-01', 'email' => 'o@example.test', 'mobile_number' => '+63 917 000 0000', 'address' => '1 Rizal St.'],
        ]))->assertCreated()->assertJsonCount(2, 'data.fund_types')->json('data.id');

        $this->assertEqualsCanonicalizing($funds->pluck('id')->all(), Policy::find($id)->fundTypes()->pluck('fund_types.id')->all());
    }

    public function test_inactive_fund_types_cannot_be_added_but_are_kept_where_used(): void
    {
        $this->signIn();
        $owner = Client::factory()->born('1975-01-01')->create();
        $inactive = FundType::factory()->create(['is_active' => false]);
        $active = FundType::factory()->create();
        $other = Policy::factory()->selfInsured($owner)->create();
        $using = Policy::factory()->selfInsured($owner)->create();
        $using->fundTypes()->attach($inactive);

        $this->putJson("/api/policies/{$other->id}", $this->policyPayload($other, ['fund_type_ids' => [$inactive->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['fund_type_ids.0' => 'Choose active fund types only.']);

        $this->putJson("/api/policies/{$using->id}", $this->policyPayload($using, ['fund_type_ids' => [$inactive->id, $active->id]]))->assertOk();
    }

    public function test_a_fund_type_used_by_a_policy_cannot_be_deleted(): void
    {
        $this->signIn('admin');
        $fund = FundType::factory()->create();
        $unused = FundType::factory()->create();
        Policy::factory()->create()->fundTypes()->attach($fund);

        $this->deleteJson("/api/fund-types/{$fund->id}")->assertStatus(409);
        $this->assertModelExists($fund);
        $this->getJson('/api/fund-types?sort=policies&direction=desc')->assertOk()->assertJsonPath('data.0.policies_count', 1);

        $this->postJson('/api/fund-types/bulk-delete', ['ids' => [$fund->id, $unused->id]])
            ->assertOk()->assertJsonPath('deleted', 1)->assertJsonPath('skipped.0.id', $fund->id);
        $this->assertModelMissing($unused);
    }

    public function test_deleting_a_policy_removes_its_fund_type_links(): void
    {
        $this->signIn('admin');
        $fund = FundType::factory()->create();
        $policy = Policy::factory()->create();
        $policy->fundTypes()->attach($fund);

        $this->deleteJson("/api/policies/{$policy->id}")->assertNoContent();
        $this->assertDatabaseMissing('fund_type_policy', ['fund_type_id' => $fund->id]);
        $this->deleteJson("/api/fund-types/{$fund->id}")->assertNoContent();
    }
}

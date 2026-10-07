<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Policy;
use App\Models\Product;
use Tests\TestCase;

class ProductTest extends TestCase
{
    public function test_create_view_update_and_delete_a_plan(): void
    {
        $this->signIn('advisor');

        $id = $this->postJson('/api/products', ['name' => '  Secure  Future  Plus ', 'plan_type' => 'VUL'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Secure Future Plus')
            ->assertJsonPath('data.plan_type', 'VUL')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.policies_count', 0)
            ->json('data.id');

        $this->getJson("/api/products/{$id}")->assertOk()->assertJsonPath('data.name', 'Secure Future Plus');

        $this->putJson("/api/products/{$id}", ['name' => 'Secure Future', 'plan_type' => 'TRAD', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.plan_type', 'TRAD')->assertJsonPath('data.is_active', false);

        $this->assertTrue(AuditLog::where(['module' => 'products', 'action' => 'updated', 'record_id' => $id])->exists());

        $this->deleteJson("/api/products/{$id}")->assertNoContent();
        $this->assertNull(Product::find($id));
    }

    public function test_validation(): void
    {
        $this->signIn();
        $existing = Product::factory()->create(['name' => 'Taken Plan']);

        $this->postJson('/api/products', ['name' => '', 'plan_type' => 'UL'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'plan_type']);
        $this->postJson('/api/products', ['name' => 'Taken Plan', 'plan_type' => 'TRAD'])->assertUnprocessable()->assertJsonValidationErrors('name');
        // Renaming a plan to its own name is fine.
        $this->putJson("/api/products/{$existing->id}", ['name' => 'Taken Plan', 'plan_type' => 'VUL'])->assertOk();
    }

    public function test_plans_used_by_client_records_cannot_be_deleted(): void
    {
        $this->signIn('admin');
        $used = $this->product();
        Policy::factory()->selfInsured(Client::factory()->create())->create(['product_id' => $used->id]);
        $unused = Product::factory()->create();

        $this->deleteJson("/api/products/{$used->id}")->assertStatus(409)
            ->assertJsonFragment(['message' => 'This plan cannot be deleted because 1 client record use it. Mark it inactive instead.']);

        $this->postJson('/api/products/bulk-delete', ['ids' => [$used->id, $unused->id]])
            ->assertOk()->assertJsonPath('deleted', 1)->assertJsonPath('skipped.0.id', $used->id);

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
    }

    public function test_list_search_filters_and_counts(): void
    {
        $this->signIn();
        Product::factory()->create(['name' => 'Alpha VUL Growth', 'plan_type' => 'VUL', 'is_active' => true]);
        Product::factory()->create(['name' => 'Beta Legacy', 'plan_type' => 'TRAD', 'is_active' => false]);

        $this->getJson('/api/products?search=Alpha')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.plan_type', 'VUL');
        $names = collect($this->getJson('/api/products?plan_type=VUL&per_page=100')->json('data'))->pluck('plan_type')->unique()->values()->all();
        $this->assertSame(['VUL'], $names);
        $this->getJson('/api/products?status=inactive')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Beta Legacy');
        // The starter plans were typed on migration: the VUL one is VUL.
        $this->assertSame('VUL', Product::where('code', 'RB-WBV')->value('plan_type'));
    }

    public function test_inactive_plans_cannot_be_chosen_for_new_records_but_existing_ones_keep_theirs(): void
    {
        $this->signIn();
        $owner = Client::factory()->create();
        $plan = $this->product();
        $policy = Policy::factory()->selfInsured($owner)->create(['product_id' => $plan->id]);
        $plan->update(['is_active' => false]);

        $payload = fn (array $o = []) => array_merge([
            'policy_number' => 'RB-NEW-1', 'policy_owner_id' => $owner->id, 'insured_same_as_owner' => true, 'product_id' => $plan->id,
            'ape' => 1000, 'sum_assured' => 50000, 'issued_date' => now()->subDay()->toDateString(), 'mode_of_payment' => 'annual', 'status' => 'active',
        ], $o);

        $this->postJson('/api/policies', $payload())->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->putJson("/api/policies/{$policy->id}", $payload(['policy_number' => $policy->policy_number]))->assertOk();
    }

    public function test_only_admins_and_advisors_can_change_plans(): void
    {
        $this->signIn('assistant');
        $plan = Product::factory()->create();

        $this->getJson('/api/products')->assertOk();
        $this->postJson('/api/products', ['name' => 'X', 'plan_type' => 'VUL'])->assertForbidden();
        $this->putJson("/api/products/{$plan->id}", ['name' => 'X', 'plan_type' => 'VUL'])->assertForbidden();
        $this->deleteJson("/api/products/{$plan->id}")->assertForbidden();
    }
}

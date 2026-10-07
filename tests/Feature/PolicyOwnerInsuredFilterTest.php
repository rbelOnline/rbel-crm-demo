<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The most important business rule: Policy Owner and Policy Insured are
 * separate relationships and every filter must honour exactly one of them.
 */
class PolicyOwnerInsuredFilterTest extends TestCase
{
    private function numbers(string $query): array
    {
        return collect($this->getJson("/api/policies?{$query}")->assertOk()->json('data'))
            ->pluck('policy_number')->sort()->values()->all();
    }

    public function test_owner_and_insured_are_stored_as_different_people(): void
    {
        $this->signIn();
        ['juan' => $juan, 'maria' => $maria, 'a' => $a] = $this->ownerInsuredScenario();

        $this->getJson("/api/policies/{$a->id}")
            ->assertOk()
            ->assertJsonPath('data.policy_owner.id', $juan->id)
            ->assertJsonPath('data.policy_insured.id', $maria->id)
            ->assertJsonPath('data.policy_owner.last_name', $juan->refresh()->last_name)
            ->assertJsonPath('data.is_self_insured', false);
    }

    public function test_birth_month_filters_apply_to_one_role_each(): void
    {
        $this->signIn();
        ['juan' => $juan, 'maria' => $maria, 'pedro' => $pedro] = $this->ownerInsuredScenario();
        $juan->update(['birthdate' => '1975-03-10']);
        $pedro->update(['birthdate' => '1955-03-22']);
        $maria->update(['birthdate' => '2013-07-04']);

        // March owners: Juan (A) and Pedro (C). Juan is only the insured on C, which is irrelevant here.
        $this->assertSame(['RB-A-0001', 'RB-C-0003'], $this->numbers('owner_birth_month=3'));
        // March insured: only Juan, insured on C.
        $this->assertSame(['RB-C-0003'], $this->numbers('insured_birth_month=3'));
        // July: Maria owns B, and is insured on A and B.
        $this->assertSame(['RB-B-0002'], $this->numbers('owner_birth_month=7'));
        $this->assertSame(['RB-A-0001', 'RB-B-0002'], $this->numbers('insured_birth_month=7'));

        $this->getJson('/api/policies?owner_birth_month=13')->assertUnprocessable()->assertJsonValidationErrors('owner_birth_month');

        // General birth month (Clients module): owner OR insured born that month.
        // March: Juan owns A, Pedro owns C, Juan insured on C.
        $this->assertSame(['RB-A-0001', 'RB-C-0003'], $this->numbers('birth_month=3'));
        // July: Maria owns B and is insured on A.
        $this->assertSame(['RB-A-0001', 'RB-B-0002'], $this->numbers('birth_month=7'));
        $this->assertSame([], $this->numbers('birth_month=12'));
    }

    public function test_filter_by_policy_owner_ignores_policies_where_person_is_only_insured(): void
    {
        $this->signIn();
        ['juan' => $juan, 'maria' => $maria] = $this->ownerInsuredScenario();

        // Juan owns A; he is only the INSURED on C, which must not appear.
        $this->assertSame(['RB-A-0001'], $this->numbers("policy_owner_id={$juan->id}"));
        // Maria owns only B (she is insured on A but does not own it).
        $this->assertSame(['RB-B-0002'], $this->numbers("policy_owner_id={$maria->id}"));
    }

    public function test_filter_by_policy_insured_ignores_policies_where_person_is_only_owner(): void
    {
        $this->signIn();
        ['juan' => $juan, 'maria' => $maria, 'pedro' => $pedro] = $this->ownerInsuredScenario();

        $this->assertSame(['RB-C-0003'], $this->numbers("policy_insured_id={$juan->id}"));
        $this->assertSame(['RB-A-0001', 'RB-B-0002'], $this->numbers("policy_insured_id={$maria->id}"));
        $this->assertSame([], $this->numbers("policy_insured_id={$pedro->id}"));
    }

    public function test_owner_and_insured_filters_combine_independently(): void
    {
        $this->signIn();
        ['juan' => $juan, 'maria' => $maria] = $this->ownerInsuredScenario();

        $this->assertSame(['RB-A-0001'], $this->numbers("policy_owner_id={$juan->id}&policy_insured_id={$maria->id}"));
        $this->assertSame([], $this->numbers("policy_owner_id={$maria->id}&policy_insured_id={$juan->id}"));
    }

    public function test_role_specific_text_search(): void
    {
        $this->signIn();
        $this->ownerInsuredScenario();

        $this->assertSame(['RB-A-0001'], $this->numbers('owner_search=Juan'));
        $this->assertSame(['RB-C-0003'], $this->numbers('insured_search=Juan'));
        $this->assertSame(['RB-C-0003'], $this->numbers('owner_search=Zamora'));
        $this->assertSame([], $this->numbers('insured_search=Zamora'));
        // Global search matches either role.
        $this->assertSame(['RB-A-0001', 'RB-C-0003'], $this->numbers('search=Juan'));
    }

    public function test_sort_by_owner_differs_from_sort_by_insured(): void
    {
        $this->signIn();
        $this->ownerInsuredScenario();

        $byOwner = collect($this->getJson('/api/policies?sort=policy_owner&direction=asc')->json('data'))->pluck('policy_number')->all();
        $byInsured = collect($this->getJson('/api/policies?sort=policy_insured&direction=asc')->json('data'))->pluck('policy_number')->all();

        // Owners: Dela Cruz(Juan)=A, Dela Cruz(Maria)=B, Zamora=C
        $this->assertSame(['RB-A-0001', 'RB-B-0002', 'RB-C-0003'], $byOwner);
        // Insureds: Dela Cruz(Juan)=C, then Maria A/B
        $this->assertSame('RB-C-0003', $byInsured[0]);
    }

    public function test_relationship_filter_separates_self_insured_from_third_party(): void
    {
        $this->signIn();
        $this->ownerInsuredScenario();

        $this->assertSame(['RB-B-0002'], $this->numbers('relationship=self'));
        $this->assertSame(['RB-A-0001', 'RB-C-0003'], $this->numbers('relationship=different'));
    }

    public function test_client_profile_lists_owned_and_insured_policies_separately(): void
    {
        $this->signIn();
        ['juan' => $juan] = $this->ownerInsuredScenario();

        $response = $this->getJson("/api/clients/{$juan->id}")->assertOk();

        $this->assertSame(['RB-A-0001'], collect($response->json('data.owned_policies'))->pluck('policy_number')->all());
        $this->assertSame(['RB-C-0003'], collect($response->json('data.insured_policies'))->pluck('policy_number')->all());
        $this->assertSame(['owner', 'insured'], collect($response->json('meta.policy_activity'))->pluck('role')->all());
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $this->signIn();

        $this->getJson('/api/policies?sort=drop_table')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/policies?status=bogus')->assertUnprocessable();
        $this->getJson('/api/policies?per_page=500')->assertUnprocessable();
    }
}

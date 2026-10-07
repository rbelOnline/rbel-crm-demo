<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Goal;
use App\Models\Client;
use App\Models\Policy;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardAndAnalyticsTest extends TestCase
{
    private function range(array $ranges, string $label): array
    {
        return collect($ranges)->firstWhere('label', $label);
    }

    public function test_monthly_sales_returns_twelve_gap_filled_months(): void
    {
        $this->signIn();
        $this->ownerInsuredScenario();
        Policy::factory()->status('postponed')->create(['issued_date' => now()->year.'-01-05', 'ape' => 999999]);

        $data = $this->getJson('/api/analytics/sales?year='.now()->year)->assertOk()->json('data');

        $this->assertCount(12, $data['months']);
        $this->assertSame(30000.0, (float) $data['months'][0]['total_ape']); // Jan: A + B, postponed excluded
        $this->assertSame(2, $data['months'][0]['policy_count']);
        $this->assertSame(40000.0, (float) $data['months'][1]['total_ape']); // Feb: C
        $this->assertSame(70000.0, (float) $data['months'][11]['running_total']);
        $this->assertSame(1, $data['months'][1]['sales_rank']);
        $this->assertSame(0.0, (float) $data['months'][5]['total_ape']);
    }

    public function test_sales_for_a_person_depends_on_the_role(): void
    {
        $this->signIn();
        ['juan' => $juan] = $this->ownerInsuredScenario();
        $year = now()->year;

        $asOwner = $this->getJson("/api/analytics/sales?year={$year}&client_id={$juan->id}&role=owner")->json('data');
        $asInsured = $this->getJson("/api/analytics/sales?year={$year}&client_id={$juan->id}&role=insured")->json('data');

        $this->assertSame(10000.0, (float) $asOwner['total_ape']);   // policy A
        $this->assertSame(40000.0, (float) $asInsured['total_ape']); // policy C

        $this->getJson("/api/analytics/sales?year={$year}&client_id={$juan->id}")->assertUnprocessable();
    }

    public function test_age_graph_groups_owners_or_insureds_by_generation(): void
    {
        $this->signIn();
        $year = now()->year;
        $born = fn (string $date) => Client::factory()->born($date)->create();
        $parent = $born('1985-06-01');      // Millennial
        $child = $born('2015-03-03');       // Gen Alpha
        $boomer = $born('1950-01-01');      // Baby Boomer
        $genZ = $born('2000-12-31');        // Gen Z
        $noBirthdate = Client::factory()->create(['birthdate' => null]);
        $issued = ['issued_date' => "{$year}-01-15"];

        Policy::factory()->ownedBy($parent)->insuring($child)->create($issued);
        Policy::factory()->ownedBy($parent)->insuring($parent)->create($issued);
        Policy::factory()->ownedBy($boomer)->insuring($genZ)->create($issued);
        Policy::factory()->ownedBy($noBirthdate)->insuring($noBirthdate)->create($issued);
        Policy::factory()->ownedBy($genZ)->insuring($genZ)->status('postponed')->create($issued); // not a sale: ignored

        $owners = $this->getJson("/api/analytics/age-distribution?year={$year}&role=owner")->assertOk()->json('data.ranges');
        $insureds = $this->getJson("/api/analytics/age-distribution?year={$year}&role=insured")->assertOk()->json('data.ranges');

        // Youngest first; the five main generations are always listed, others only when present.
        $this->assertSame(['Gen Alpha', 'Gen Z', 'Millennials', 'Gen X', 'Baby Boomers', 'Unknown'], array_column($owners, 'label'));
        $this->assertSame('1981–1996', $this->range($owners, 'Millennials')['years']);

        // Owners: the parent (2 policies), the boomer, and one with no birthdate.
        $this->assertSame(1, $this->range($owners, 'Millennials')['person_count']);
        $this->assertSame(2, $this->range($owners, 'Millennials')['policy_count']);
        $this->assertSame(1, $this->range($owners, 'Baby Boomers')['person_count']);
        $this->assertSame(0, $this->range($owners, 'Gen Z')['person_count']); // their only owned policy is postponed
        $this->assertSame(1, $this->range($owners, 'Unknown')['person_count']);
        $this->assertEquals(33.33, $this->range($owners, 'Millennials')['pct']);

        // Insureds: child, parent, Gen Z, and the one with no birthdate; the boomer is never insured.
        $this->assertSame(1, $this->range($insureds, 'Gen Alpha')['person_count']);
        $this->assertSame(1, $this->range($insureds, 'Gen Z')['person_count']);
        $this->assertSame(1, $this->range($insureds, 'Millennials')['person_count']);
        $this->assertSame(0, $this->range($insureds, 'Baby Boomers')['person_count']);

        $this->getJson('/api/analytics/age-distribution?role=client')->assertUnprocessable();
    }

    public function test_top_clients_rank_by_role(): void
    {
        $this->signIn();
        ['pedro' => $pedro, 'juan' => $juan] = $this->ownerInsuredScenario();

        $owners = $this->getJson('/api/analytics?role=owner')->json('data.top_clients');
        $insureds = $this->getJson('/api/analytics?role=insured')->json('data.top_clients');

        $this->assertSame($pedro->id, $owners[0]['client_id']);
        $this->assertSame(1, $owners[0]['ape_rank']);
        $this->assertSame($juan->id, $insureds[0]['client_id']); // insured under C (40k)
        $this->assertNotContains($pedro->id, array_column($insureds, 'client_id'));
    }

    public function test_dashboard_kpis_and_panels(): void
    {
        $this->signIn();
        ['juan' => $juan, 'pedro' => $pedro, 'a' => $a] = $this->ownerInsuredScenario();
        $today = now();

        // Pending delivery.
        $a->update(['policy_delivery_date' => null]);
        // Premium due today: annual policy issued exactly one year ago.
        Policy::factory()->selfInsured($juan)->create([
            'policy_number' => 'RB-DUE-1', 'mode_of_payment' => 'annual', 'status' => 'active',
            'issued_date' => $today->copy()->subYear()->toDateString(), 'policy_delivery_date' => $today->copy()->subYear()->addWeek()->toDateString(),
        ]);
        // Anniversary: issued in this month, two years ago.
        $anniv = Policy::factory()->selfInsured($juan)->create([
            'policy_number' => 'RB-ANN-1', 'issued_date' => $today->copy()->subYears(2)->startOfMonth()->toDateString(), 'status' => 'active',
        ]);
        // Birthday this month.
        $bday = Client::factory()->born($today->copy()->subYears(30)->startOfMonth()->toDateString())->create();
        Appointment::factory()->create(['client_id' => $juan->id, 'appointment_date' => $today->toDateString(), 'status' => 'scheduled']);
        // Pedro owns only policy C (40,000 APE, in force).
        // Policies issued this year: A 10,000 + B 20,000 + C 40,000 = 70,000 of 140,000.
        Goal::factory()->create(['target_amount' => 140000, 'status' => 'in_progress', 'start_date' => $today->copy()->startOfYear()->toDateString(), 'target_date' => $today->copy()->endOfYear()->toDateString()]);
        Client::factory()->create(); // a lead: not a client record

        // This year only: A, B and C. RB-DUE-1 and RB-ANN-1 were issued in earlier years.
        $data = $this->getJson("/api/dashboard?year={$today->year}&age_role=insured")->assertOk()->json('data');

        // Total Clients = client records (policies) issued this year: A, B, C.
        $this->assertSame(3, $data['kpis']['total_clients']);
        $this->assertSame(1, $data['kpis']['pending_delivery']);
        $this->assertSame(1, $data['kpis']['appointments_today']);
        $this->assertSame(1, $data['kpis']['goals_active']);
        $this->assertEquals(50, $data['kpis']['goals_avg_progress']);

        $types = collect($data['due_today'])->pluck('type');
        $this->assertTrue($types->contains('premium'));
        $this->assertTrue($types->contains('appointment'));

        // Due items carry an email recipient; premiums go to the Policy Owner, with the policy for placeholders.
        // Look the item up by policy: on the 1st of a month RB-ANN-1 can also be due today.
        $dueId = Policy::where('policy_number', 'RB-DUE-1')->value('id');
        $premium = collect($data['due_today'])->first(fn ($i) => $i['type'] === 'premium' && $i['policy_id'] === $dueId);
        $this->assertNotNull($premium);
        $this->assertSame($juan->id, $premium['client_id']);
        $this->assertStringStartsWith('/clients/', $premium['link']);
        $this->assertSame($juan->id, collect($data['due_today'])->firstWhere('type', 'appointment')['client_id']);

        $this->assertContains($bday->id, array_column($data['birthdays'], 'id'));
        $this->assertContains($anniv->id, array_column($data['anniversaries'], 'id'));
        $this->assertSame(2, collect($data['anniversaries'])->firstWhere('id', $anniv->id)['years']);
        $this->assertCount(12, $data['sales']['months']);
        $this->assertSame('insured', $data['age_distribution']['role']);
    }

    public function test_dashboard_kpis_follow_the_year(): void
    {
        $this->signIn();
        $owner = fn () => Client::factory()->born('1980-01-01')->create();
        $in = fn (int $year, string $status, ?string $delivered = '2000-01-01') => Policy::factory()->selfInsured($owner())->status($status)
            ->create(['issued_date' => "{$year}-06-15", 'policy_delivery_date' => $delivered ? "{$year}-06-20" : null]);

        $in(2020, 'active', null);   // 2020: in force, not delivered
        $in(2020, 'lapsed');          // 2020: churned
        $in(2021, 'terminated');      // 2021: churned
        $in(2023, 'active');          // 2023
        Goal::factory()->create(['target_amount' => 1000, 'status' => 'in_progress', 'target_date' => '2021-12-31']);
        Goal::factory()->create(['target_amount' => 1000, 'status' => 'in_progress', 'target_date' => '2023-12-31']);

        $kpis = fn (string $qs) => $this->getJson("/api/dashboard?{$qs}")->assertOk()->json('data.kpis');

        // 2020 only: 2 policies, 1 undelivered, 1 churned owner of 2 → 50%, no goal due.
        $k = $kpis('year=2020');
        $this->assertSame([2, 1, 1, 0], [$k['total_clients'], $k['pending_delivery'], $k['inactive_clients'], $k['goals_active']]);
        $this->assertEquals(50.0, $k['churn_rate_pct']);

        // 2021 only: 1 churned policy, the goal due in 2021.
        $k = $kpis('year=2021');
        $this->assertSame([1, 1, 1], [$k['total_clients'], $k['inactive_clients'], $k['goals_active']]);
        $this->assertEquals(100.0, $k['churn_rate_pct']);

        $k = $kpis('year=2023');
        $this->assertSame([1, 0, 1], [$k['total_clients'], $k['inactive_clients'], $k['goals_active']]);

        // Default: the current year.
        $this->assertSame(now()->year, $this->getJson('/api/dashboard')->json('data.filters.year'));
        $this->assertSame(0, $kpis('')['total_clients']);
        $this->getJson('/api/dashboard?year=1900')->assertUnprocessable()->assertJsonValidationErrors('year');
    }

    public function test_reporting_views_expose_owner_and_insured_separately(): void
    {
        ['a' => $a, 'juan' => $juan, 'maria' => $maria] = $this->ownerInsuredScenario();

        $row = DB::table('vw_policy_overview')->where('policy_id', $a->id)->first();
        $this->assertSame($juan->id, (int) $row->owner_id);
        $this->assertSame($maria->id, (int) $row->insured_id);
        $this->assertSame(0, (int) $row->is_self_insured);

        $this->assertSame(3, DB::table('vw_client_age_analytics')->where('person_role', 'owner')->count());
        $this->assertSame(3, DB::table('vw_client_age_analytics')->where('person_role', 'insured')->count());
    }

    public function test_only_terminated_lapsed_or_surrendered_policies_count_as_churn(): void
    {
        $this->signIn();
        $owner = fn () => Client::factory()->born('1970-01-01')->create();
        $policyFor = fn (Client $c, string $status) => Policy::factory()->selfInsured($c)->status($status)->create(['issued_date' => '2020-01-01']);

        $churned = [];
        foreach (['terminated', 'lapsed', 'surrendered'] as $status) {
            $policyFor($churned[$status] = $owner(), $status);
        }
        $matured = $owner();
        $policyFor($matured, 'matured');
        $postponed = $owner();
        $policyFor($postponed, 'postponed');
        $active = $owner();
        $policyFor($active, 'lapsed');
        $policyFor($active, 'active'); // something still in force: not churned

        $status = fn (Client $c) => DB::table('vw_client_policy_overview')->where('client_id', $c->id)->value('client_status');
        foreach ($churned as $c) {
            $this->assertSame('inactive', $status($c));
        }
        $this->assertSame('completed', $status($matured));
        $this->assertSame('prospect', $status($postponed));
        $this->assertSame('active', $status($active));

        // The API agrees with the view, and the filter uses the same rule.
        $this->getJson("/api/clients/{$matured->id}")->assertOk()->assertJsonPath('data.client_status', 'completed');
        $this->getJson("/api/clients/{$churned['lapsed']->id}")->assertOk()->assertJsonPath('data.client_status', 'inactive');
        $ids = fn (string $s) => collect($this->getJson("/api/clients?client_status={$s}&per_page=100")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect($churned)->pluck('id')->sort()->values()->all(), $ids('inactive'));
        $this->assertSame([$matured->id], $ids('completed'));
        $this->assertSame([$postponed->id], $ids('prospect'));

        // Churn rate = 3 churned ÷ (1 active + 3 churned).
        $retention = app(\App\Services\AnalyticsService::class)->retention();
        $this->assertSame(3, $retention['inactive_clients']);
        $this->assertSame(1, $retention['completed_clients']);
        $this->assertEquals(75.0, $retention['churn_rate_pct']);
    }

    public function test_a_cooling_off_policy_is_in_force(): void
    {
        $this->signIn();
        $owner = Client::factory()->born('1980-01-01')->create();
        $policy = Policy::factory()->selfInsured($owner)->status('cooling_off')->create([
            'issued_date' => now()->subDays(5)->toDateString(), 'policy_delivery_date' => null, 'mode_of_payment' => 'monthly',
        ]);

        // The owner is an active client, not churned.
        $this->assertSame('active', DB::table('vw_client_policy_overview')->where('client_id', $owner->id)->value('client_status'));
        $this->getJson("/api/clients/{$owner->id}")->assertOk()->assertJsonPath('data.client_status', 'active')->assertJsonPath('data.in_force_owned_count', 1);
        // Undelivered: tracked as pending delivery; its premiums fall due.
        $this->assertTrue(DB::table('vw_policy_delivery_monitoring')->where('policy_id', $policy->id)->exists());
        $this->assertTrue(DB::table('vw_premiums_due')->where('policy_id', $policy->id)->exists());
        $this->assertSame(1, $this->getJson('/api/dashboard')->assertOk()->json('data.kpis.pending_delivery'));

        // It is a valid status on the form.
        $this->getJson('/api/meta')->assertOk()->assertJsonFragment(['policy_statuses' => Policy::STATUSES]);
        $this->assertContains('cooling_off', Policy::STATUSES);
    }
}

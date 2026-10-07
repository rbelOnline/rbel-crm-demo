<?php

namespace Tests;

use App\Models\Client;
use App\Models\Policy;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Tests run against MySQL (see phpunit.xml) because the app relies on views,
 * stored procedures, CTEs, window functions and generated columns.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Views reference tables, so drop them on migrate:fresh. */
    protected bool $dropViews = true;

    /** Products exist in every test. */
    protected bool $seed = true;

    protected string $seeder = ReferenceDataSeeder::class;

    protected function signIn(string $role = 'advisor'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    protected function product(): Product
    {
        return Product::where('code', 'RB-LP20')->firstOrFail();
    }

    /**
     * The canonical owner ≠ insured fixture:
     *  A: Juan owns,  Maria insured   (parent/spouse pays for another's cover)
     *  B: Maria owns, Maria insured   (self-insured)
     *  C: Pedro owns, Juan insured    (Juan appears ONLY as insured here)
     *
     * @return array{juan: Client, maria: Client, pedro: Client, a: Policy, b: Policy, c: Policy}
     */
    protected function ownerInsuredScenario(): array
    {
        $juan = Client::factory()->born(now()->subYears(50)->subDays(30)->toDateString())->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'gender' => 'male']);
        $maria = Client::factory()->born(now()->subYears(12)->subDays(30)->toDateString())->create(['first_name' => 'Maria', 'last_name' => 'Dela Cruz', 'gender' => 'female']);
        $pedro = Client::factory()->born(now()->subYears(70)->subDays(30)->toDateString())->create(['first_name' => 'Pedro', 'last_name' => 'Zamora', 'gender' => 'male']);

        $product = $this->product();
        $year = now()->year;

        $a = Policy::factory()->ownedBy($juan)->insuring($maria)->create([
            'policy_number' => 'RB-A-0001', 'product_id' => $product->id, 'mode_of_payment' => 'annual', 'ape' => 10000, 'issued_date' => "{$year}-01-15", 'policy_delivery_date' => "{$year}-01-20",
        ]);
        $b = Policy::factory()->selfInsured($maria)->create([
            'policy_number' => 'RB-B-0002', 'product_id' => $product->id, 'mode_of_payment' => 'annual', 'ape' => 20000, 'issued_date' => "{$year}-01-20", 'policy_delivery_date' => "{$year}-01-25",
        ]);
        $c = Policy::factory()->ownedBy($pedro)->insuring($juan)->create([
            'policy_number' => 'RB-C-0003', 'product_id' => $product->id, 'mode_of_payment' => 'annual', 'ape' => 40000, 'issued_date' => "{$year}-02-10", 'policy_delivery_date' => "{$year}-02-15",
        ]);

        return compact('juan', 'maria', 'pedro', 'a', 'b', 'c');
    }
}

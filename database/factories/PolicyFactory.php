<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Policy;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * By default owner and insured are DIFFERENT people; use selfInsured() for
 * the owner-is-insured case.
 *
 * @extends Factory<Policy>
 */
class PolicyFactory extends Factory
{
    public function definition(): array
    {
        $issued = fake()->dateTimeBetween('-4 years', 'now');
        $delivered = (clone $issued)->modify('+'.fake()->numberBetween(3, 20).' days');

        return [
            'policy_number' => 'RB-'.$issued->format('Y').'-'.fake()->unique()->numerify('######'),
            'policy_owner_id' => Client::factory(),
            'policy_insured_id' => Client::factory()->insuredOnly(),
            'product_id' => Product::factory(),
            'ape' => fake()->randomFloat(2, 12000, 150000),
            'issued_date' => $issued->format('Y-m-d'),
            'mode_of_payment' => fake()->randomElement(Policy::PAYMENT_MODES),
            'sum_assured' => fake()->randomElement([500000, 1000000, 2000000, 3000000, 5000000]),
            'status' => 'active',
            'policy_delivery_date' => $delivered > now() ? null : $delivered->format('Y-m-d'),
            'is_orphan' => false,
        ];
    }

    public function ownedBy(Client $owner): static
    {
        return $this->state(fn () => ['policy_owner_id' => $owner->id]);
    }

    public function insuring(Client $insured): static
    {
        return $this->state(fn () => ['policy_insured_id' => $insured->id]);
    }

    public function selfInsured(Client $client): static
    {
        return $this->state(fn () => ['policy_owner_id' => $client->id, 'policy_insured_id' => $client->id]);
    }

    public function issuedOn(string $date): static
    {
        return $this->state(fn () => ['issued_date' => $date, 'policy_delivery_date' => null]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}

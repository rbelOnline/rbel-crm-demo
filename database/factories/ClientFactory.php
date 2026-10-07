<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic people only — no real personal information. Clients may own a
 * policy by default; use insuredOnly() for a client who can only be insured.
 *
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        $gender = fake()->randomElement(['male', 'female']);
        $first = fake()->firstName($gender);
        $last = fake()->lastName();

        return [
            'first_name' => $first,
            'middle_name' => fake()->optional(0.7)->lastName(),
            'last_name' => $last,
            'occupation' => fake()->jobTitle(),
            'birthdate' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'gender' => $gender,
            'email' => strtolower($first.'.'.$last.fake()->unique()->numberBetween(1, 99999)).'@example.test',
            'mobile_number' => '+63 9'.fake()->numerify('## ### ####'),
            'address' => fake()->streetAddress().', '.fake()->city(),
            'is_policy_owner' => true,
        ];
    }

    public function born(string $date): static
    {
        return $this->state(fn () => ['birthdate' => $date]);
    }

    public function insuredOnly(): static
    {
        return $this->state(fn () => ['is_policy_owner' => false]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Policy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Beneficiary> */
class BeneficiaryFactory extends Factory
{
    public function definition(): array
    {
        $gender = fake()->randomElement(['male', 'female']);

        return [
            'policy_id' => Policy::factory(),
            'first_name' => fake()->firstName($gender),
            'middle_name' => fake()->optional(0.5)->lastName(),
            'last_name' => fake()->lastName(),
            'birthdate' => fake()->dateTimeBetween('-60 years', '-1 year')->format('Y-m-d'),
            'gender' => $gender,
            'relationship' => fake()->randomElement(['spouse', 'child', 'parent', 'sibling']),
            'beneficiary_type' => 'primary',
            'designation' => 'revocable',
            'allocation_percentage' => 100,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lead> */
class LeadFactory extends Factory
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
            'notes' => fake()->optional()->sentence(),
        ];
    }
}

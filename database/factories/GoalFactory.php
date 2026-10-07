<?php

namespace Database\Factories;

use App\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Goal> */
class GoalFactory extends Factory
{
    public function definition(): array
    {
        // current_amount is derived from policy APE when the goal is saved (GoalProgressService).
        return [
            'title' => fake()->randomElement(['Quarterly APE target', 'New clients this quarter', 'Emergency fund', 'College fund']),
            'description' => fake()->sentence(),
            'target_amount' => fake()->randomElement([100000, 250000, 500000, 1000000]),
            'start_date' => today()->toDateString(),
            'target_date' => fake()->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d'),
            'status' => 'in_progress',
        ];
    }
}

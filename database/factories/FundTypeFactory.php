<?php

namespace Database\Factories;

use App\Models\FundType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FundType> */
class FundTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Peso Equity', 'Peso Bond', 'Balanced', 'Dollar Bond', 'Money Market', 'Global Equity']).' Fund '.fake()->unique()->numberBetween(1, 9999),
            'suitability' => fake()->randomElement(\App\Models\FundType::SUITABILITIES),
            'is_active' => true,
        ];
    }
}

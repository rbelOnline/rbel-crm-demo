<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('PRD-###??')),
            'name' => 'Test Plan '.fake()->unique()->numberBetween(1, 99999),
            'plan_type' => fake()->randomElement(Product::PLAN_TYPES),
            'category' => fake()->randomElement(['life', 'health', 'investment', 'education', 'retirement']),
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/** Starter products. Safe to re-run. */
class ReferenceDataSeeder extends Seeder
{
    public const PRODUCTS = [
        ['code' => 'RB-LP20', 'name' => 'RBEL Life Protect 20', 'plan_type' => 'TRAD', 'category' => 'life'],
        ['code' => 'RB-WLL', 'name' => 'RBEL Whole Life Legacy', 'plan_type' => 'TRAD', 'category' => 'life'],
        ['code' => 'RB-TG10', 'name' => 'RBEL Term Guard 10', 'plan_type' => 'TRAD', 'category' => 'life'],
        ['code' => 'RB-WBV', 'name' => 'RBEL Wealth Builder VUL', 'plan_type' => 'VUL', 'category' => 'investment'],
        ['code' => 'RB-EFP', 'name' => 'RBEL EduFuture Plan', 'plan_type' => 'TRAD', 'category' => 'education'],
        ['code' => 'RB-HS', 'name' => 'RBEL Health Shield', 'plan_type' => 'TRAD', 'category' => 'health'],
        ['code' => 'RB-CCP', 'name' => 'RBEL Critical Care Plus', 'plan_type' => 'TRAD', 'category' => 'health'],
        ['code' => 'RB-RS', 'name' => 'RBEL Retire Secure', 'plan_type' => 'TRAD', 'category' => 'retirement'],
    ];

    public function run(): void
    {
        // Create missing starter plans only; plans edited in the Products module are left alone.
        foreach (self::PRODUCTS as $product) {
            Product::firstOrCreate(['code' => $product['code']], $product + ['is_active' => true]);
        }

    }
}

<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'title' => fake()->randomElement(['Policy review', 'Needs analysis', 'Policy delivery', 'Claims assistance', 'Retirement planning']),
            'description' => fake()->sentence(),
            'appointment_date' => fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
            'appointment_time' => fake()->randomElement(['09:00', '10:30', '13:00', '15:30', '17:00']),
            'location' => fake()->randomElement(['Office', 'Client residence', 'Video call', 'Coffee shop']),
            'status' => 'scheduled',
        ];
    }
}

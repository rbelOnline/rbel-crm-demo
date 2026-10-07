<?php

namespace Database\Factories;

use App\Models\Reminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reminder> */
class ReminderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'follow_up',
            'title' => 'Follow up with client',
            'due_date' => today()->toDateString(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailTemplate> */
class EmailTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Template '.fake()->unique()->numberBetween(1, 99999),
            'subject' => 'Hello {{first_name}}',
            'body' => "Dear {{first_name}} {{last_name}},\n\nThank you for trusting us.\n\n{{advisor_name}}",
            'status' => 'active',
        ];
    }
}

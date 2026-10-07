<?php

namespace App\Http\Requests;

use App\Models\Reminder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Reminder::TYPES)],
            'title' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['required', 'date'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'policy_id' => ['nullable', 'integer', 'exists:policies,id'],
            'completed' => ['sometimes', 'boolean'],
        ];
    }
}

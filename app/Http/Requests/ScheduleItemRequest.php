<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use App\Models\ScheduleItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Add / edit a personal schedule item. */
class ScheduleItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            // Repeat every day / week / month / year from `date`, optionally until a last day.
            'repeat' => ['sometimes', Rule::in(ScheduleItem::REPEATS)],
            'repeat_until' => ['nullable', 'date', 'after_or_equal:date'],
            // Same colour labels as appointments.
            'label' => ['nullable', Rule::in(Appointment::LABELS)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_time.after' => 'End time must be after the start time.',
            'repeat_until.after_or_equal' => 'The last day cannot be before the first day.',
        ];
    }
}

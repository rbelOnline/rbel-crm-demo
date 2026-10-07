<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'appointment_date' => ['required', 'date'],
            'appointment_time' => ['required', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:191'],
            'status' => ['required', Rule::in(Appointment::STATUSES)],
            'label' => ['nullable', Rule::in(Appointment::LABELS)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

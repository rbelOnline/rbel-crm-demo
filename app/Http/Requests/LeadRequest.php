<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Create / update a lead. */
class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ClientRequest::trimmed($this, ['first_name', 'middle_name', 'last_name', 'email', 'mobile_number', 'occupation', 'notes', 'gender', 'birthdate']));
    }

    public function rules(): array
    {
        return ClientRequest::personRules() + [
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return ClientRequest::personMessages();
    }
}

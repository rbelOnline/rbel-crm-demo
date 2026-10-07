<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Turn a lead into a client. The lead's details are copied; the address and owner flag are set here. */
class ConvertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ClientRequest::trimmed($this, ['address']));
    }

    public function rules(): array
    {
        return [
            'address' => ['nullable', 'string', 'max:255'],
            'is_policy_owner' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Http\Controllers\ClientController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'is_policy_owner' => ['nullable', 'boolean'],
            'role' => ['nullable', 'in:owner,insured,owner_only,insured_only'],
            'client_status' => ['nullable', 'in:active,inactive,completed,prospect'],
            'gender' => ['nullable', 'in:male,female,other'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'age_min' => ['nullable', 'integer', 'between:0,130'],
            'age_max' => ['nullable', 'integer', 'between:0,130', 'gte:age_min'],
            'sort' => ['nullable', Rule::in(array_keys(ClientController::SORTABLE))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

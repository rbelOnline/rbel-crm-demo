<?php

namespace App\Http\Requests;

use App\Http\Controllers\LeadController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeadIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'in:male,female,other'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'age_min' => ['nullable', 'integer', 'between:0,130'],
            'age_max' => ['nullable', 'integer', 'between:0,130', 'gte:age_min'],
            'sort' => ['nullable', Rule::in(array_keys(LeadController::SORTABLE))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

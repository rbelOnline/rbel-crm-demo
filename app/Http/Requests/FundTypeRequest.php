<?php

namespace App\Http\Requests;

use App\Models\FundType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FundTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => preg_replace('/\s+/', ' ', trim((string) $this->input('name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('fund_types', 'name')->ignore($this->route('fund_type')?->id)],
            'suitability' => ['required', Rule::in(FundType::SUITABILITIES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Fund type name is required.',
            'name.unique' => 'A fund type with this name already exists.',
            'suitability.required' => 'Choose a suitability.',
            'suitability.in' => 'Choose a suitability from the list.',
        ];
    }
}

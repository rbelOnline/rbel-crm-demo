<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120', Rule::unique('products', 'name')->ignore($this->route('product')?->id)],
            'plan_type' => ['required', Rule::in(Product::PLAN_TYPES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Plan name is required.',
            'name.unique' => 'A plan with this name already exists.',
            'plan_type.required' => 'Choose a plan type.',
            'plan_type.in' => 'Plan type must be VUL or TRAD.',
        ];
    }
}

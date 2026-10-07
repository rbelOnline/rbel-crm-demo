<?php

namespace App\Http\Requests;

use App\Models\Policy;
use App\Services\PolicySearch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query-string validation for the policy list (search, filters, sort, paging). */
class PolicyIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Accept comma-separated multi-selects: ?status=active,lapsed
        foreach (['status', 'mode_of_payment', 'product_id'] as $key) {
            if (is_string($this->query($key))) {
                $this->merge([$key => array_values(array_filter(explode(',', $this->query($key)), 'strlen'))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'owner_search' => ['nullable', 'string', 'max:100'],
            'insured_search' => ['nullable', 'string', 'max:100'],
            'policy_owner_id' => ['nullable', 'integer', 'min:1'],
            'policy_insured_id' => ['nullable', 'integer', 'min:1'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'owner_birth_month' => ['nullable', 'integer', 'between:1,12'],
            'insured_birth_month' => ['nullable', 'integer', 'between:1,12'],
            'product_id' => ['nullable', 'array'],
            'product_id.*' => ['integer'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(Policy::STATUSES)],
            'mode_of_payment' => ['nullable', 'array'],
            'mode_of_payment.*' => [Rule::in(Policy::PAYMENT_MODES)],
            'is_orphan' => ['nullable', 'in:0,1,true,false'],
            'issued_from' => ['nullable', 'date'],
            'issued_to' => ['nullable', 'date', 'after_or_equal:issued_from'],
            'year' => ['nullable', 'integer', 'between:1950,2100'],
            'delivery' => ['nullable', 'in:pending,delivered'],
            'relationship' => ['nullable', 'in:self,different'],
            'sort' => ['nullable', Rule::in(array_keys(PolicySearch::SORTABLE))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

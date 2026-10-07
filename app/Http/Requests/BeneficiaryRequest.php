<?php

namespace App\Http\Requests;

use App\Models\Beneficiary;
use App\Models\Policy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Add / edit a single beneficiary on an existing policy. */
class BeneficiaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ClientRequest::trimmed($this, Beneficiary::DETAIL_FIELDS));
    }

    public function rules(): array
    {
        return PolicyRequest::beneficiaryRules();
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Policy $policy */
            $policy = $this->route('policy');
            /** @var Beneficiary|null $current */
            $current = $this->route('beneficiary');

            $items = $policy->beneficiaries()
                ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                ->get(['beneficiary_type', 'allocation_percentage'])
                ->map(fn ($b) => $b->only(['beneficiary_type', 'allocation_percentage']))
                ->all();
            $items[] = [
                'beneficiary_type' => $this->input('beneficiary_type', 'primary'),
                'allocation_percentage' => $this->input('allocation_percentage'),
            ];

            // Only the "exceeds 100%" rule applies here; the total may still be
            // incomplete while beneficiaries are added one at a time.
            foreach (PolicyRequest::allocationErrors($items) as $message) {
                if (str_contains($message, 'exceeds')) {
                    $validator->errors()->add('allocation_percentage', $message);
                }
            }
        }];
    }

    public function messages(): array
    {
        return ClientRequest::personMessages();
    }
}

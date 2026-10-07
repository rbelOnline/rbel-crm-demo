<?php

namespace App\Http\Requests;

use App\Models\Policy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Preview or send a template for a recipient. Previews may use unsaved
 * subject/body (live editor preview); sends always use the stored template.
 */
class EmailPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => [$this->routeIs('*.send') ? 'required' : 'nullable', 'integer', 'exists:clients,id'],
            'policy_id' => ['nullable', 'integer', 'exists:policies,id'],
            'subject' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'string', 'max:20000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled(['client_id', 'policy_id'])) {
                return;
            }

            // The recipient must actually be a party to the chosen policy.
            $related = Policy::whereKey($this->integer('policy_id'))
                ->where(fn ($q) => $q->where('policy_owner_id', $this->integer('client_id'))
                    ->orWhere('policy_insured_id', $this->integer('client_id')))
                ->exists();

            if (! $related) {
                $validator->errors()->add('policy_id', 'The selected policy is not owned by or insuring this client.');
            }
        }];
    }
}

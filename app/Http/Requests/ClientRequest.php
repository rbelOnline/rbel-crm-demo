<?php

namespace App\Http\Requests;

use App\Rules\PersonName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Create / update a client. */
class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::trimmed($this, ['first_name', 'middle_name', 'last_name', 'email', 'mobile_number', 'address', 'occupation', 'gender', 'birthdate']));
    }

    public function rules(): array
    {
        return self::personRules() + [
            'address' => ['nullable', 'string', 'max:255'],
            'is_policy_owner' => ['sometimes', 'boolean'],
        ];
    }

    /** A client who owns policies must stay flagged as a policy owner. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $client = $this->route('client');

            if ($client && $this->has('is_policy_owner') && ! $this->boolean('is_policy_owner') && $client->ownedPolicies()->exists()) {
                $validator->errors()->add('is_policy_owner', 'This client owns policies, so they must stay a Policy Owner.');
            }
        }];
    }

    /** Blank strings become null; other values are trimmed. */
    public static function trimmed(FormRequest $request, array $fields): array
    {
        return array_map(
            fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v,
            $request->only($fields)
        );
    }

    /** A person's details, shared by clients, leads, beneficiaries and the owner / insured on the policy form. */
    public static function personRules(string $prefix = ''): array
    {
        return [
            "{$prefix}first_name" => ['required', 'string', 'max:80', new PersonName],
            "{$prefix}middle_name" => ['nullable', 'string', 'max:80', new PersonName],
            "{$prefix}last_name" => ['required', 'string', 'max:80', new PersonName],
            "{$prefix}birthdate" => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            "{$prefix}gender" => ['nullable', 'in:male,female,other'],
            "{$prefix}email" => ['nullable', 'email:rfc', 'max:191'],
            "{$prefix}mobile_number" => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            "{$prefix}occupation" => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return self::personMessages();
    }

    public static function personMessages(): array
    {
        return [
            'mobile_number.regex' => 'Enter a valid mobile number (digits, spaces, +, - and parentheses only).',
            'birthdate.before_or_equal' => 'Birthdate cannot be in the future.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Services\MailSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'host' => ['required_if:enabled,true', 'nullable', 'string', 'max:191', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(MailSettings::ENCRYPTIONS)],
            'username' => ['required_if:enabled,true', 'nullable', 'string', 'max:191'],
            // Blank keeps the saved password.
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'email:rfc', 'max:191'],
            'from_name' => ['nullable', 'string', 'max:100', 'not_regex:/[\r\n]/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->boolean('enabled')) {
                return;
            }

            // The sender address defaults to the username, so one of them must be an email address.
            if (blank($this->input('from_address')) && ! filter_var($this->input('username'), FILTER_VALIDATE_EMAIL)) {
                $validator->errors()->add('from_address', 'Enter the sender address (the username is not an email address).');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'host.required_if' => 'Enter the mail server (e.g. smtp.gmail.com).',
            'host.regex' => 'Enter a server name such as smtp.gmail.com.',
            'username.required_if' => 'Enter the mailbox login (usually the email address).',
        ];
    }
}

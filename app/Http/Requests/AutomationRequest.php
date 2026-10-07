<?php

namespace App\Http\Requests;

use App\Models\EmailTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'email_template_id' => ['nullable', 'integer', 'exists:email_templates,id'],
            'send_time' => ['required', 'date_format:H:i'],
            'sender_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->boolean('enabled')) {
                return;
            }

            // Turning an automation on requires an active template to send.
            $template = $this->filled('email_template_id') ? EmailTemplate::find($this->integer('email_template_id')) : null;
            if (! $template) {
                $validator->errors()->add('email_template_id', 'Choose an email template before turning this on.');
            } elseif ($template->status !== 'active') {
                $validator->errors()->add('email_template_id', 'Only active templates can be sent. Activate the template first.');
            }
        }];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\EmailImage;
use App\Models\EmailTemplate;
use App\Services\TemplateRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('email_templates', 'name')->ignore($this->route('email_template')?->id)],
            'subject' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]/'],
            'body' => ['required', 'string', 'max:20000'],
            'status' => ['required', Rule::in(EmailTemplate::STATUSES)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            // Every {{image:ID}} must point at an uploaded image.
            $ids = TemplateRenderer::imageIds((string) $this->input('body'));
            $missing = array_diff($ids, EmailImage::whereKey($ids)->pluck('id')->all());

            if ($missing !== []) {
                $validator->errors()->add('body', 'Unknown image '.implode(', ', array_map(fn ($id) => "{{image:{$id}}}", $missing)).'. Use "Insert image" to add images.');
            }
        }];
    }
}

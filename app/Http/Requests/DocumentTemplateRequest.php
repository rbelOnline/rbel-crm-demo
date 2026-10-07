<?php

namespace App\Http\Requests;

use App\Services\DocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create a template (file required) or update it (new file optional). */
class DocumentTemplateRequest extends FormRequest
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
        $updating = (bool) $this->route('document_template');

        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('document_templates', 'name')->ignore($this->route('document_template')?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'file' => [$updating ? 'nullable' : 'required', 'file', 'mimes:'.implode(',', DocumentService::TEMPLATE_MIMES), 'max:'.DocumentService::MAX_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A document with this name already exists.',
            'file.required' => 'Choose a file to upload.',
            'file.mimes' => 'Upload a Word (.docx, .doc), PDF, Excel or OpenDocument file.',
            'file.max' => 'Files can be at most 10 MB.',
        ];
    }
}

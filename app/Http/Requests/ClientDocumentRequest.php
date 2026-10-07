<?php

namespace App\Http\Requests;

use App\Models\DocumentTemplate;
use App\Services\DocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add a document to a client record (from a template, or an uploaded file),
 * or update one (rename, optionally replace the file).
 */
class ClientDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $fileRule = ['file', 'mimes:'.implode(',', DocumentService::CLIENT_MIMES), 'max:'.DocumentService::MAX_KB];

        if ($this->route('document')) {
            return [
                'name' => ['required', 'string', 'max:150'],
                'file' => ['nullable', ...$fileRule],
            ];
        }

        return [
            'source' => ['required', 'in:template,upload'],
            'document_template_id' => ['required_if:source,template', 'nullable', 'integer', Rule::exists('document_templates', 'id')->where('is_active', true)],
            'file' => ['required_if:source,upload', 'nullable', ...$fileRule],
            'name' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** A PDF template with placeholders is filled in the browser, which sends the filled copy as `file`. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->route('document') || $this->input('source') !== 'template' || $validator->errors()->isNotEmpty()) {
                return;
            }
            $template = DocumentTemplate::find($this->input('document_template_id'));
            if (! $template?->hasPdfFields()) {
                return;
            }
            $file = $this->file('file');
            if (! $file) {
                $validator->errors()->add('file', 'The filled PDF is missing. Please try again.');
            } elseif ($file->getMimeType() !== 'application/pdf') {
                $validator->errors()->add('file', 'The filled document must be a PDF.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'document_template_id.required_if' => 'Choose a template.',
            'document_template_id.exists' => 'Choose an active template.',
            'file.required_if' => 'Choose a file to upload.',
            'file.mimes' => 'Upload a PDF, Word, Excel, OpenDocument, JPG or PNG file.',
            'file.max' => 'Files can be at most 10 MB.',
        ];
    }
}

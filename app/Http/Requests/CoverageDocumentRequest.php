<?php

namespace App\Http\Requests;

use App\Services\CoverageDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class CoverageDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // File::types checks the real MIME type from content, not just the extension.
            'document' => ['required', File::types(CoverageDocumentService::ALLOWED_MIMES)->max(CoverageDocumentService::MAX_KB)],
        ];
    }

    public function messages(): array
    {
        return [
            'document.mimes' => 'Coverage documents must be a PDF, JPG or PNG file.',
            'document.max' => 'Coverage documents may not be larger than 10 MB.',
        ];
    }
}

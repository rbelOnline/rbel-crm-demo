<?php

namespace App\Http\Requests;

use App\Services\DocumentService;
use App\Support\PdfFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Fields positioned on a PDF in the PDF editor: on a template (JSON), or on a client
 * document (multipart: `fields` as a JSON string plus `file`, the PDF stamped in the browser).
 * A field is a placeholder key, "text" with its own fixed `text`, or "image" with `src` (data URL).
 */
class PdfFieldsRequest extends FormRequest
{
    public const MAX_FIELDS = 300;

    public const TEXT = PdfFields::TEXT;

    public const IMAGE = PdfFields::IMAGE;

    /** Images are resized in the browser before upload; these keep the request well under PHP's post size. */
    public const MAX_IMAGES = 10;

    public const MAX_IMAGE_CHARS = 700_000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('fields'))) {
            $this->merge(['fields' => json_decode($this->input('fields'), true) ?? 'invalid']);
        }
    }

    public function rules(): array
    {
        return [
            'fields' => ['present', 'array', 'max:'.self::MAX_FIELDS],
            'fields.*.key' => ['required', 'string', Rule::in([...array_keys(DocumentService::PLACEHOLDERS), self::TEXT, self::IMAGE])],
            'fields.*.text' => ['nullable', 'required_if:fields.*.key,'.self::TEXT, 'string', 'max:500'],
            'fields.*.src' => ['nullable', 'required_if:fields.*.key,'.self::IMAGE, 'string', 'max:'.self::MAX_IMAGE_CHARS, 'regex:#^data:image/(png|jpeg);base64,[A-Za-z0-9+/]+=*$#'],
            'fields.*.page' => ['required', 'integer', 'between:1,2000'],
            'fields.*.x' => ['required', 'numeric', 'between:0,1'],
            'fields.*.y' => ['required', 'numeric', 'between:0,1'],
            'fields.*.w' => ['required', 'numeric', 'between:0.005,1'],
            'fields.*.h' => ['required', 'numeric', 'between:0.003,1'],
            'fields.*.size' => ['required', 'numeric', 'between:4,72'],
            'fields.*.align' => ['required', 'in:left,center,right'],
            ...($this->forClientDocument() ? ['file' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.DocumentService::MAX_KB]] : []),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $target = $this->route('document') ?? $this->route('document_template');
            if ($target?->extension !== 'pdf') {
                $validator->errors()->add('fields', 'Fields can only be placed on PDF documents.');
            }
            if (count(array_filter((array) $this->input('fields'), fn ($f) => ($f['key'] ?? null) === self::IMAGE)) > self::MAX_IMAGES) {
                $validator->errors()->add('fields', 'A document can have at most '.self::MAX_IMAGES.' images.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'fields.array' => 'The fields could not be read. Please try again.',
            'fields.max' => 'A document can have at most '.self::MAX_FIELDS.' fields.',
            'fields.*.key.in' => 'Choose a field from the list.',
            'fields.*.text.required_if' => 'Type the text for each text box, or remove the empty ones.',
            'fields.*.src.required_if' => 'An image box has no image. Upload one, or remove the box.',
            'fields.*.src.max' => 'An image is too large. Use a smaller image.',
            'fields.*.src.regex' => 'Images must be PNG or JPG.',
            'file.required' => 'The filled PDF is missing. Please try again.',
            'file.mimetypes' => 'The filled document must be a PDF.',
            'file.max' => 'Files can be at most 10 MB.',
        ];
    }

    public function forClientDocument(): bool
    {
        return (bool) $this->route('document');
    }

    /** Normalized field list (rounded, in a stable key order). */
    public function fields(): array
    {
        return array_map(fn (array $f) => [
            'key' => $f['key'],
            'page' => (int) $f['page'],
            'x' => round((float) $f['x'], 5),
            'y' => round((float) $f['y'], 5),
            'w' => round((float) $f['w'], 5),
            'h' => round((float) $f['h'], 5),
            'size' => round((float) $f['size'], 1),
            'align' => $f['align'],
        ] + match ($f['key']) {
            self::TEXT => ['text' => (string) $f['text']],
            self::IMAGE => ['src' => (string) $f['src']],
            default => [],
        }, array_values($this->validated('fields')));
    }

    /** Placeholder keys used (not text or image boxes). */
    public function placeholderKeys(): array
    {
        return array_values(array_unique(array_diff(array_column($this->fields(), 'key'), [self::TEXT, self::IMAGE])));
    }
}

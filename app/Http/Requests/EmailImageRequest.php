<?php

namespace App\Http\Requests;

use App\Models\EmailImage;
use Illuminate\Foundation\Http\FormRequest;

class EmailImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `image` + mimes checks the real content type, not just the extension.
            'image' => ['required', 'file', 'image', 'mimes:'.implode(',', EmailImage::MIMES), 'max:'.EmailImage::MAX_KB, 'dimensions:max_width=4000,max_height=4000'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.mimes' => 'Upload a JPG, PNG, GIF or WEBP image.',
            'image.image' => 'Upload a JPG, PNG, GIF or WEBP image.',
            'image.max' => 'Images can be at most 2 MB.',
        ];
    }
}

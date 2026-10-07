<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Ids selected in a list for mass delete. Authorization (can:manage) is on the route. */
class BulkDeleteRequest extends FormRequest
{
    public const MAX = 100;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_map('intval', $this->validated('ids'));
    }
}

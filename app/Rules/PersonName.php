<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A person's first, middle or last name: letters (any language, e.g. "Peña"),
 * spaces, periods, apostrophes and hyphens ("Ma. Cristina", "O'Neil", "Dela Cruz-Santos").
 * No numbers. Must start with a letter.
 */
class PersonName implements ValidationRule
{
    public const PATTERN = "/^\p{L}[\p{L}\p{M}\s'.\-]*$/u";

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (preg_match('/\d/u', $value)) {
            $fail('The :attribute cannot contain numbers.');
        } elseif (! preg_match(self::PATTERN, $value)) {
            $fail('The :attribute may only contain letters, spaces, periods, apostrophes and hyphens.');
        }
    }
}

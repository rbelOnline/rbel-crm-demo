<?php

namespace App\Support;

/**
 * Title Case for names, occupations and addresses: "juan DELA cruz" → "Juan Dela Cruz",
 * "santos-reyes" → "Santos-Reyes". Name particles that must stay as people write them
 * are kept: "O'neil" → "O'Neil", "iii" → "III". Spacing is not touched beyond trimming.
 */
class TitleCase
{
    /** Generational suffixes, kept upper case ("Juan Dela Cruz III"). */
    private const ROMAN = ['ii', 'iii', 'iv', 'vi', 'vii', 'viii', 'ix'];

    public static function apply(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_convert_case(trim($value), MB_CASE_TITLE, 'UTF-8');

        // O'Neil, D'Angelo: capitalize after a one-letter prefix and apostrophe.
        $value = preg_replace_callback("/\b(\p{L})['’](\p{Ll})/u", fn ($m) => $m[1]."'".mb_strtoupper($m[2]), $value);

        // Roman-numeral suffixes as whole words.
        return preg_replace_callback('/\b(\p{L}+)\b/u', fn ($m) => in_array(mb_strtolower($m[1]), self::ROMAN, true) ? mb_strtoupper($m[1]) : $m[1], $value);
    }
}

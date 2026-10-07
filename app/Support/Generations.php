<?php

namespace App\Support;

/**
 * Generations by birth year (commonly used Pew Research boundaries), youngest first.
 * Used by the age graphs instead of age ranges.
 */
class Generations
{
    /** key => [label, first birth year, last birth year (null = open)] */
    public const ALL = [
        'gen_alpha' => ['Gen Alpha', 2013, null],
        'gen_z' => ['Gen Z', 1997, 2012],
        'millennials' => ['Millennials', 1981, 1996],
        'gen_x' => ['Gen X', 1965, 1980],
        'boomers' => ['Baby Boomers', 1946, 1964],
        'silent' => ['Silent Generation', 1928, 1945],
        'greatest' => ['Greatest Generation', null, 1927],
    ];

    /** Always listed, even with nobody in them; the rest only when someone is. */
    public const ALWAYS_SHOWN = ['gen_alpha', 'gen_z', 'millennials', 'gen_x', 'boomers'];

    /** "1981–1996", "2013+", "≤1927". */
    public static function years(string $key): string
    {
        [, $from, $to] = self::ALL[$key];

        return match (true) {
            $from === null => "≤{$to}",
            $to === null => "{$from}+",
            default => "{$from}–{$to}",
        };
    }

    /** SQL CASE giving the generation key for a birthdate column ('unknown' when empty). */
    public static function caseSql(string $birthdate): string
    {
        $whens = '';
        foreach (self::ALL as $key => [, $from, $to]) {
            $whens .= match (true) {
                $from === null => " WHEN YEAR({$birthdate}) <= {$to} THEN '{$key}'",
                $to === null => " WHEN YEAR({$birthdate}) >= {$from} THEN '{$key}'",
                default => " WHEN YEAR({$birthdate}) BETWEEN {$from} AND {$to} THEN '{$key}'",
            };
        }

        return "CASE WHEN {$birthdate} IS NULL THEN 'unknown'{$whens} END";
    }
}

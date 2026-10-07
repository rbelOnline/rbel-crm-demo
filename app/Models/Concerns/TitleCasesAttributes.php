<?php

namespace App\Models\Concerns;

use App\Support\TitleCase;

/**
 * Stores the model's $titleCase attributes in Title Case, and its $lowerCase
 * attributes (e.g. email) in lower case, whenever a record is created or edited,
 * however it was saved (forms, policy form, lead conversion).
 */
trait TitleCasesAttributes
{
    public static function bootTitleCasesAttributes(): void
    {
        static::saving(function (self $model) {
            $format = [
                ...array_fill_keys($model->titleCase, fn (string $v) => TitleCase::apply($v)),
                ...array_fill_keys($model->lowerCase ?? [], fn (string $v) => mb_strtolower(trim($v), 'UTF-8')),
            ];

            foreach ($format as $attribute => $apply) {
                $value = $model->getAttributes()[$attribute] ?? null;

                if (is_string($value) && $model->isDirty($attribute)) {
                    $model->setAttribute($attribute, $apply($value));
                }
            }
        });
    }
}

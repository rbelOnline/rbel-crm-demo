<?php

namespace App\Support;

/** Helpers for fields placed in the PDF editor (document_templates / client_documents.pdf_fields). */
class PdfFields
{
    /** A box holding its own fixed text. */
    public const TEXT = 'text';

    /** A box holding an uploaded image (`src`: a PNG or JPEG data URL). */
    public const IMAGE = 'image';

    /** Embedded images are left out of the audit trail: only their position is recorded. */
    public static function redactImages(array $values): array
    {
        if (! array_key_exists('pdf_fields', $values) || $values['pdf_fields'] === null) {
            return $values;
        }

        $fields = is_string($values['pdf_fields']) ? json_decode($values['pdf_fields'], true) : $values['pdf_fields'];
        if (is_array($fields)) {
            $values['pdf_fields'] = array_map(fn ($f) => is_array($f) && isset($f['src']) ? ['src' => '[image]'] + $f : $f, $fields);
        }

        return $values;
    }
}

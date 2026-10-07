<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\PdfFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable document (Documents module). A .docx is filled with a client's details
 * ({{placeholders}}); so is a PDF whose placeholders were positioned in the PDF editor.
 */
#[Fillable(['name', 'description', 'path', 'original_name', 'extension', 'mime', 'size', 'placeholders', 'pdf_fields', 'is_active', 'uploaded_by'])]
class DocumentTemplate extends Model
{
    use Auditable;

    protected string $auditModule = 'document_templates';

    protected function auditRedact(array $values): array
    {
        return PdfFields::redactImages($values);
    }

    protected function casts(): array
    {
        return ['placeholders' => 'array', 'pdf_fields' => 'array', 'is_active' => 'boolean', 'size' => 'integer'];
    }

    /** Word .docx files and PDFs with positioned placeholders are filled in; other files are copied as-is. */
    public function canBeFilledIn(): bool
    {
        return $this->extension === 'docx' || $this->hasPdfFields();
    }

    /** A PDF with placeholders: client documents are made by stamping the client's details onto it. */
    public function hasPdfFields(): bool
    {
        return $this->extension === 'pdf' && ! empty($this->pdf_fields);
    }

    public function clientDocuments(): HasMany
    {
        return $this->hasMany(ClientDocument::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

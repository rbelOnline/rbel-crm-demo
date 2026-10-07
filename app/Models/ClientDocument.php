<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\PdfFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file on a client record (policy): generated from a template or uploaded. */
#[Fillable(['policy_id', 'document_template_id', 'name', 'source', 'path', 'original_name', 'extension', 'mime', 'size', 'unfilled', 'content_html', 'edited_at', 'pdf_fields', 'base_path', 'created_by'])]
class ClientDocument extends Model
{
    use Auditable;

    protected string $auditModule = 'client_documents';

    /** The editor copy can be large: never serialized, and hidden columns are left out of the audit trail. */
    protected $hidden = ['content_html'];

    protected function auditRedact(array $values): array
    {
        return PdfFields::redactImages($values);
    }

    protected function casts(): array
    {
        return ['unfilled' => 'array', 'size' => 'integer', 'edited_at' => 'datetime', 'pdf_fields' => 'array'];
    }

    /** Word documents can be opened in the in-app editor. */
    public function isEditable(): bool
    {
        return $this->extension === 'docx';
    }

    /** PDFs open in the PDF editor (fields stamped onto the page). */
    public function isPdf(): bool
    {
        return $this->extension === 'pdf';
    }

    /** The unfilled PDF the editor stamps onto: the original, or the file itself before its first edit. */
    public function pdfSourcePath(): string
    {
        return $this->base_path ?? $this->path;
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

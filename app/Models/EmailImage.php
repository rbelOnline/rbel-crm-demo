<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** An image a template body references as {{image:ID}}. Stored on the private disk. */
#[Fillable(['path', 'original_name', 'mime', 'size', 'uploaded_by'])]
class EmailImage extends Model
{
    use Auditable;

    public const DISK = 'local';

    public const DIRECTORY = 'email-images';

    /** Raster formats only: SVG can carry script, so it is never accepted. */
    public const MIMES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public const MAX_KB = 2048;

    protected string $auditModule = 'email_templates';

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function absolutePath(): string
    {
        return Storage::disk(self::DISK)->path($this->path);
    }

    public function token(): string
    {
        return "{{image:{$this->id}}}";
    }
}

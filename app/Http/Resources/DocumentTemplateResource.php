<?php

namespace App\Http\Resources;

use App\Models\DocumentTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentTemplate */
class DocumentTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'file_name' => $this->original_name,
            'extension' => $this->extension,
            'size' => $this->size,
            'fillable' => $this->canBeFilledIn(),
            'placeholders' => $this->placeholders ?? [],
            // Positioned PDF placeholders: left out of the list to keep it light.
            'pdf_fields' => $this->when($this->extension === 'pdf' && ! $request->routeIs('document-templates.index'), fn () => $this->pdf_fields ?? []),
            'is_active' => $this->is_active,
            'client_documents_count' => $this->whenCounted('clientDocuments'),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

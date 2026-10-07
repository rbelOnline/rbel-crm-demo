<?php

namespace App\Http\Resources;

use App\Models\ClientDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientDocument */
class ClientDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'policy_id' => $this->policy_id,
            'name' => $this->name,
            'source' => $this->source,
            'template' => $this->whenLoaded('template', fn () => $this->template ? ['id' => $this->template->id, 'name' => $this->template->name] : null),
            'file_name' => $this->original_name,
            'extension' => $this->extension,
            'size' => $this->size,
            'unfilled' => $this->unfilled ?? [],
            'editable' => $this->isEditable(),
            // Which in-app editor opens it: the Word editor, the PDF editor, or none.
            'editor' => $this->isEditable() ? 'word' : ($this->isPdf() ? 'pdf' : null),
            'pdf_fields' => $this->when($this->isPdf(), fn () => $this->pdf_fields ?? []),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

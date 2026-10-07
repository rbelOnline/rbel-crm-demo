<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteRequest;
use App\Http\Requests\DocumentTemplateRequest;
use App\Http\Requests\PdfFieldsRequest;
use App\Http\Resources\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use App\Services\BulkDelete;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Documents module: reusable templates for client documents. */
class DocumentTemplateController extends Controller
{
    private const SORTABLE = ['name' => 'name', 'extension' => 'extension', 'used' => 'client_documents_count', 'updated_at' => 'updated_at'];

    public function __construct(private DocumentService $documents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'kind' => ['nullable', 'in:fillable,other'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTABLE))],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = DocumentTemplate::with('uploader:id,name')->withCount('clientDocuments')
            ->when($f['search'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($t, '%_\\').'%')->orWhere('description', 'like', '%'.addcslashes($t, '%_\\').'%')))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->when($f['kind'] ?? null, function ($q, $k) {
                $fillable = fn ($w) => $w->where('extension', 'docx')->orWhere(fn ($p) => $p->where('extension', 'pdf')->whereJsonLength('pdf_fields', '>', 0));
                $k === 'fillable' ? $q->where($fillable) : $q->whereNot($fillable);
            })
            ->orderBy(self::SORTABLE[$f['sort'] ?? 'name'], ($f['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');

        return DocumentTemplateResource::collection($query->paginate($f['per_page'] ?? 10)->withQueryString())
            ->additional(['placeholders' => DocumentService::PLACEHOLDERS]);
    }

    public function store(DocumentTemplateRequest $request): JsonResponse
    {
        try {
            $file = $this->documents->storeTemplateFile($request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
        }

        $template = DocumentTemplate::create(Arr::only($request->validated(), ['name', 'description', 'is_active']) + $file + ['uploaded_by' => $request->user()->id]);

        return (new DocumentTemplateResource($template->load('uploader:id,name')->loadCount('clientDocuments')))->response()->setStatusCode(201);
    }

    public function show(DocumentTemplate $documentTemplate): DocumentTemplateResource
    {
        return (new DocumentTemplateResource($documentTemplate->load('uploader:id,name')->loadCount('clientDocuments')))
            ->additional(['placeholders' => DocumentService::PLACEHOLDERS]);
    }

    /** Update details; a new file replaces the old one (client documents already made are unaffected). */
    public function update(DocumentTemplateRequest $request, DocumentTemplate $documentTemplate): DocumentTemplateResource|JsonResponse
    {
        $attributes = Arr::only($request->validated(), ['name', 'description', 'is_active']);
        $oldPath = null;

        if ($request->hasFile('file')) {
            try {
                $attributes += $this->documents->storeTemplateFile($request->file('file'));
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage(), 'errors' => ['file' => [$e->getMessage()]]], 422);
            }
            $oldPath = $documentTemplate->path;

            // A revised PDF keeps its positioned placeholders (adjust them in the PDF editor); any other file drops them.
            if ($attributes['extension'] === 'pdf' && $documentTemplate->extension === 'pdf') {
                unset($attributes['placeholders']);
            } else {
                $attributes['pdf_fields'] = null;
            }
        }

        $documentTemplate->update($attributes);
        if ($oldPath) {
            $this->documents->deleteFile($oldPath);
        }

        return $this->show($documentTemplate);
    }

    /** Save the placeholders positioned on a PDF in the PDF editor. */
    public function savePdfFields(PdfFieldsRequest $request, DocumentTemplate $documentTemplate): DocumentTemplateResource
    {
        $fields = $request->fields();
        $documentTemplate->update([
            'pdf_fields' => $fields ?: null,
            'placeholders' => $request->placeholderKeys() ?: null,
        ]);

        return $this->show($documentTemplate);
    }

    public function download(DocumentTemplate $documentTemplate): StreamedResponse
    {
        return $this->documents->response($documentTemplate->path, $documentTemplate->original_name, $documentTemplate->mime, download: true);
    }

    /** Client documents made from it keep their files; they just lose the link. */
    public function destroy(DocumentTemplate $documentTemplate): JsonResponse
    {
        $documentTemplate->delete();
        $this->documents->deleteFile($documentTemplate->path);

        return response()->json(null, 204);
    }

    public function bulkDestroy(BulkDeleteRequest $request, BulkDelete $bulk): JsonResponse
    {
        return $bulk->run(DocumentTemplate::class, $request->ids(), fn () => null, function (DocumentTemplate $t) {
            $t->delete();
            $this->documents->deleteFile($t->path);
        }, fn (DocumentTemplate $t) => $t->name);
    }
}

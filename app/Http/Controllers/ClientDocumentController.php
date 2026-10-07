<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientDocumentRequest;
use App\Http\Requests\PdfFieldsRequest;
use App\Http\Resources\ClientDocumentResource;
use App\Models\ClientDocument;
use App\Models\DocumentTemplate;
use App\Models\Policy;
use App\Services\DocumentService;
use App\Support\DocumentHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Documents on one client record (policy). Routes use scoped bindings. */
class ClientDocumentController extends Controller
{
    public function __construct(private DocumentService $documents) {}

    public function index(Policy $policy): AnonymousResourceCollection
    {
        return ClientDocumentResource::collection(
            $policy->documents()->with(['template:id,name', 'creator:id,name'])->latest()->latest('id')->get()
        );
    }

    /** From a template (a .docx is filled with this record's details) or an uploaded file. */
    public function store(ClientDocumentRequest $request, Policy $policy): JsonResponse
    {
        $name = $request->validated('name');

        try {
            $document = $request->validated('source') === 'template'
                ? $this->documents->createFromTemplate($policy, DocumentTemplate::findOrFail($request->validated('document_template_id')), $name, $request->user(), $request->file('file'))
                : $this->documents->upload($policy, $request->file('file'), $name, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'The document could not be created: '.$e->getMessage()], 422);
        }

        return (new ClientDocumentResource($document->load(['template:id,name', 'creator:id,name'])))->response()->setStatusCode(201);
    }

    /** Rename, and optionally replace the file (e.g. with the signed copy). */
    public function update(ClientDocumentRequest $request, Policy $policy, ClientDocument $document): ClientDocumentResource
    {
        if ($request->hasFile('file')) {
            $this->documents->replaceFile($document, $request->file('file'));
        }
        $document->update(['name' => $request->validated('name')]);

        return new ClientDocumentResource($document->refresh()->load(['template:id,name', 'creator:id,name']));
    }

    /** The editor copy (null until first edited in the app: the browser then opens the .docx itself). */
    public function content(Policy $policy, ClientDocument $document): JsonResponse
    {
        return response()->json(['data' => [
            'editable' => $document->isEditable(),
            'html' => $document->content_html,
        ]]);
    }

    public function saveContent(Request $request, Policy $policy, ClientDocument $document): JsonResponse
    {
        abort_unless($document->isEditable(), 422, 'Only Word (.docx) documents can be edited in the app.');
        $html = $request->validate(['html' => ['present', 'nullable', 'string', 'max:'.DocumentHtml::MAX_BYTES]])['html'] ?? '';

        try {
            $this->documents->saveContent($document, $html, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => (new ClientDocumentResource($document->refresh()->load(['template:id,name', 'creator:id,name'])))->resolve() + ['html' => $document->content_html]]);
    }

    /** Client details for "Insert field" and "Autofill client data". */
    public function fields(Request $request, Policy $policy): JsonResponse
    {
        return response()->json(['data' => $this->documents->fields($policy, $request->user())]);
    }

    public function download(Policy $policy, ClientDocument $document): StreamedResponse
    {
        return $this->documents->response($document->path, $document->original_name, $document->mime, download: true);
    }

    /** The unfilled PDF the PDF editor stamps its fields onto. */
    public function pdfSource(Policy $policy, ClientDocument $document): StreamedResponse
    {
        abort_unless($document->isPdf(), 422, 'Only PDF documents open in the PDF editor.');

        return $this->documents->response($document->pdfSourcePath(), $document->original_name, 'application/pdf', download: true);
    }

    /** Save the PDF editor: the fields placed and the PDF stamped with them in the browser. */
    public function savePdf(PdfFieldsRequest $request, Policy $policy, ClientDocument $document): JsonResponse
    {
        $this->documents->savePdf($document, $request->file('file'), $request->fields(), $request->placeholderKeys(), $request->user());

        return response()->json(['data' => new ClientDocumentResource($document->refresh()->load(['template:id,name', 'creator:id,name']))]);
    }

    public function destroy(Policy $policy, ClientDocument $document): JsonResponse
    {
        $document->delete();
        $this->documents->deleteClientFiles($document);

        return response()->json(null, 204);
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\EmailNotSendable;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDeleteRequest;
use App\Http\Requests\EmailPreviewRequest;
use App\Http\Requests\EmailTemplateRequest;
use App\Http\Resources\EmailLogResource;
use App\Http\Resources\EmailTemplateResource;
use App\Mail\TemplatedMail;
use App\Models\EmailImage;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Client;
use App\Models\Policy;
use App\Services\BulkDelete;
use App\Services\EmailSender;
use App\Services\TemplateRenderer;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmailTemplateController extends Controller
{
    public const NOT_CONFIGURED = EmailSender::NOT_CONFIGURED;

    public function __construct(private TemplateRenderer $renderer) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(EmailTemplate::STATUSES)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = EmailTemplate::with('creator:id,name')
            ->withCount('logs')
            ->when($f['search'] ?? null, function ($q, $t) {
                $escaped = addcslashes($t, '%_\\');
                $q->where(fn ($w) => $w->where('name', 'like', "%{$escaped}%")->orWhere('subject', 'like', "%{$escaped}%"));
            })
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name');

        return EmailTemplateResource::collection($query->paginate($f['per_page'] ?? 10)->withQueryString())
            ->additional(['placeholders' => TemplateRenderer::PLACEHOLDERS]);
    }

    public function store(EmailTemplateRequest $request): JsonResponse
    {
        $template = EmailTemplate::create($request->validated() + ['created_by' => $request->user()->id]);

        return (new EmailTemplateResource($template))->response()->setStatusCode(201);
    }

    public function show(EmailTemplate $emailTemplate): EmailTemplateResource
    {
        return new EmailTemplateResource($emailTemplate->load('creator:id,name')->loadCount('logs'));
    }

    public function update(EmailTemplateRequest $request, EmailTemplate $emailTemplate): EmailTemplateResource
    {
        $emailTemplate->update($request->validated());

        return new EmailTemplateResource($emailTemplate);
    }

    public function destroy(EmailTemplate $emailTemplate): JsonResponse
    {
        $emailTemplate->delete();

        return response()->json(null, 204);
    }

    /** Mass delete. Existing email logs are kept, as with single delete. */
    public function bulkDestroy(BulkDeleteRequest $request, BulkDelete $bulk): JsonResponse
    {
        return $bulk->run(EmailTemplate::class, $request->ids(), fn () => null, fn (EmailTemplate $t) => $t->delete(), fn (EmailTemplate $t) => $t->name);
    }

    public function duplicate(Request $request, EmailTemplate $emailTemplate): JsonResponse
    {
        $name = $this->uniqueCopyName($emailTemplate->name);

        $copy = EmailTemplate::create([
            'name' => $name,
            'subject' => $emailTemplate->subject,
            'body' => $emailTemplate->body,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return (new EmailTemplateResource($copy))->response()->setStatusCode(201);
    }

    /**
     * Render the template (or unsaved subject/body from the editor) for a
     * chosen client/policy, or with sample data when none is chosen.
     */
    public function preview(EmailPreviewRequest $request, EmailTemplate $emailTemplate): JsonResponse
    {
        [$client, $policy] = $this->context($request);

        $variables = $client
            ? $this->renderer->variables($client, $policy, $request->user())
            : $this->renderer->sampleVariables($request->user());

        $body = $request->input('body', $emailTemplate->body);

        // Previews show images through the authenticated image URL.
        $images = EmailImage::whereKey(TemplateRenderer::imageIds($body))->get()
            ->mapWithKeys(fn (EmailImage $i) => [$i->id => route('email-images.show', $i, absolute: false)])
            ->all();

        $rendered = $this->renderer->render(
            $request->input('subject', $emailTemplate->subject),
            $body,
            $variables,
            $images,
        );

        return response()->json(['data' => $rendered + [
            'recipient' => $client?->email,
            'uses_sample_data' => $client === null,
        ]]);
    }

    public function send(EmailPreviewRequest $request, EmailTemplate $emailTemplate, EmailSender $sender): JsonResponse
    {
        [$client, $policy] = $this->context($request);

        try {
            $log = $sender->send($emailTemplate, $client, $policy, $request->user());
        } catch (EmailNotSendable $e) {
            return response()->json(array_filter(['message' => $e->getMessage(), 'errors' => $e->errors ?: null]), 422);
        }

        return (new EmailLogResource($log->load(['template', 'client'])))
            ->response()
            ->setStatusCode($log->status === 'sent' ? 201 : 502);
    }

    /** @return array{0: ?Client, 1: ?Policy} */
    private function context(EmailPreviewRequest $request): array
    {
        $client = $request->filled('client_id') ? Client::find($request->integer('client_id')) : null;
        $policy = $request->filled('policy_id')
            ? Policy::with(['product', 'owner', 'insured'])->find($request->integer('policy_id'))
            : null;

        return [$client, $policy];
    }

    private function uniqueCopyName(string $name): string
    {
        $base = Str::limit(preg_replace('/ \(copy( \d+)?\)$/', '', $name), 100, '');
        $candidate = "{$base} (copy)";

        for ($i = 2; EmailTemplate::where('name', $candidate)->exists(); $i++) {
            $candidate = "{$base} (copy {$i})";
        }

        return $candidate;
    }
}

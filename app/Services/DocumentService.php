<?php

namespace App\Services;

use App\Models\ClientDocument;
use App\Models\DocumentTemplate;
use App\Models\Client;
use App\Models\Policy;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\DocumentHtml;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents module: stores templates, generates client documents from them
 * (filling .docx placeholders with the client record's details) and stores
 * uploaded client files. Everything is on the private disk.
 */
class DocumentService
{
    public const DISK = 'local';

    public const TEMPLATE_MIMES = ['docx', 'doc', 'pdf', 'xlsx', 'xls', 'odt'];

    public const CLIENT_MIMES = ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'odt', 'jpg', 'jpeg', 'png'];

    public const MAX_KB = 10240;

    /**
     * Placeholders available in document templates: the email placeholders plus the
     * details of the Policy Owner and the Policy Insured, each under its own prefix.
     */
    public const PLACEHOLDERS = [
        'policy_number' => 'Policy number',
        'product' => 'Plan name',
        'plan_type' => 'Plan type (VUL / TRAD)',
        'sum_assured' => 'Sum assured',
        'ape' => 'Annualized premium',
        'premium_due' => 'Premium per payment (active policies)',
        'due_date' => 'Next premium due date (active policies)',
        'mode_of_payment' => 'Mode of payment',
        'issued_date' => 'Issue date',
        'policy_status' => 'Policy status',
        'owner_full_name' => 'Policy Owner: full name',
        'owner_first_name' => 'Policy Owner: first name',
        'owner_last_name' => 'Policy Owner: last name',
        'owner_birthdate' => 'Policy Owner: birthdate',
        'owner_age' => 'Policy Owner: age',
        'owner_gender' => 'Policy Owner: gender',
        'owner_email' => 'Policy Owner: email',
        'owner_mobile' => 'Policy Owner: mobile number',
        'owner_address' => 'Policy Owner: address',
        'owner_occupation' => 'Policy Owner: occupation',
        'insured_full_name' => 'Policy Insured: full name',
        'insured_first_name' => 'Policy Insured: first name',
        'insured_last_name' => 'Policy Insured: last name',
        'insured_birthdate' => 'Policy Insured: birthdate',
        'insured_age' => 'Policy Insured: age',
        'insured_gender' => 'Policy Insured: gender',
        'insured_email' => 'Policy Insured: email',
        'insured_mobile' => 'Policy Insured: mobile number',
        'insured_address' => 'Policy Insured: address',
        'insured_occupation' => 'Policy Insured: occupation',
        'advisor_name' => 'Your name',
        'today' => "Today's date",
    ];

    public function __construct(private DocxFiller $filler, private TemplateRenderer $renderer) {}

    /** Values for one client record. The email placeholders ({{full_name}} etc.) refer to the Policy Owner. */
    public function variables(Policy $policy, User $advisor): array
    {
        $policy->loadMissing(['owner', 'insured', 'product']);
        $person = fn (string $prefix, ?Client $p) => $p ? [
            "{$prefix}_full_name" => $p->displayName(),
            "{$prefix}_first_name" => $p->first_name,
            "{$prefix}_last_name" => $p->last_name,
            "{$prefix}_birthdate" => $p->birthdate?->format('F j, Y') ?? '',
            "{$prefix}_age" => $p->birthdate ? (string) $p->birthdate->age : '',
            "{$prefix}_gender" => $p->gender ? ucfirst($p->gender) : '',
            "{$prefix}_email" => $p->email ?? '',
            "{$prefix}_mobile" => $p->mobile_number ?? '',
            "{$prefix}_address" => $p->address ?? '',
            "{$prefix}_occupation" => $p->occupation ?? '',
        ] : [];

        return array_map('strval', $this->renderer->variables($policy->owner, $policy, $advisor)
            + $person('owner', $policy->owner)
            + $person('insured', $policy->insured)
            + [
                'plan_type' => $policy->product?->plan_type ?? '',
                'mode_of_payment' => Str::of($policy->mode_of_payment)->replace('_', '-')->title()->toString(),
                'policy_status' => Str::title((string) $policy->status),
            ]);
    }

    // ---- Templates -------------------------------------------------------

    /** Store (or replace) a template's file; returns the attributes to save. */
    public function storeTemplateFile(UploadedFile $file): array
    {
        [$path, $meta] = $this->put($file, 'documents/templates');

        try {
            $meta['placeholders'] = $meta['extension'] === 'docx' ? $this->filler->placeholders(Storage::disk(self::DISK)->path($path)) : null;
        } catch (\RuntimeException $e) {
            Storage::disk(self::DISK)->delete(array_filter((array) $paths));
            throw new \InvalidArgumentException($e->getMessage());
        }

        return ['path' => $path] + $meta;
    }

    // ---- Client documents ------------------------------------------------

    /**
     * Generate a client document from a template: a .docx is filled in here; a PDF with
     * positioned placeholders arrives already filled ($filledPdf), because it is stamped
     * in the browser with pdf-lib (PHP's free PDF libraries can't read the compressed
     * PDFs most tools save today); other formats are copied.
     */
    public function createFromTemplate(Policy $policy, DocumentTemplate $template, ?string $name, User $by, ?UploadedFile $filledPdf = null): ClientDocument
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($template->path), 404, 'The template file is missing.');

        $path = "documents/clients/{$policy->id}/".Str::uuid().'.'.$template->extension;
        $disk->makeDirectory(dirname($path));
        $unfilled = [];
        $basePath = null;

        if ($template->hasPdfFields()) {
            if (! $filledPdf) {
                throw new \RuntimeException('The filled PDF is missing.');
            }
            $filledPdf->storeAs(dirname($path), basename($path), self::DISK);
            // The unfilled original stays with the document, so its fields can be moved in the PDF editor later.
            $basePath = dirname($path).'/'.Str::uuid().'.pdf';
            $disk->copy($template->path, $basePath);
            $unfilled = $this->unfilledPdfKeys($template->placeholders ?? [], $policy, $by);
        } elseif ($template->extension === 'docx') {
            $unfilled = $this->filler->fill($disk->path($template->path), $disk->path($path), $this->variables($policy, $by));
        } else {
            $disk->copy($template->path, $path);
        }

        $label = $name ?: $template->name;
        $fileName = Str::slug("{$label} {$policy->policy_number}").'.'.$template->extension;

        return $this->record([$path, $basePath], fn () => $policy->documents()->create([
            'document_template_id' => $template->id,
            'name' => $label,
            'source' => 'template',
            'path' => $path,
            'original_name' => $fileName,
            'extension' => $template->extension,
            'mime' => $template->mime,
            'size' => $disk->size($path),
            'unfilled' => $unfilled ?: null,
            'pdf_fields' => $basePath ? $template->pdf_fields : null,
            'base_path' => $basePath,
            'created_by' => $by->id,
        ]));
    }

    public function upload(Policy $policy, UploadedFile $file, ?string $name, User $by): ClientDocument
    {
        [$path, $meta] = $this->put($file, "documents/clients/{$policy->id}");

        return $this->record($path, fn () => $policy->documents()->create($meta + [
            'path' => $path,
            'name' => $name ?: pathinfo($meta['original_name'], PATHINFO_FILENAME),
            'source' => 'upload',
            'created_by' => $by->id,
        ]));
    }

    /** Replace the file of an existing client document (e.g. with the signed copy). */
    public function replaceFile(ClientDocument $document, UploadedFile $file): ClientDocument
    {
        $old = [$document->path, $document->base_path];
        [$path, $meta] = $this->put($file, "documents/clients/{$document->policy_id}");

        // A replaced file starts fresh in the editors (the old editor copy and PDF fields no longer match it).
        $this->record($path, fn () => $document->update($meta + ['path' => $path, 'source' => 'upload', 'unfilled' => null, 'content_html' => null, 'edited_at' => null, 'pdf_fields' => null, 'base_path' => null]));
        Storage::disk(self::DISK)->delete(array_filter($old));

        return $document;
    }

    /**
     * Save the PDF editor: the browser stamps the fields onto the unfilled original
     * and sends the result, which replaces the file. The original is kept (on the first
     * edit, the current file becomes it), so later edits start from a clean page.
     */
    public function savePdf(ClientDocument $document, UploadedFile $filled, array $fields, array $placeholderKeys, User $by): ClientDocument
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($document->pdfSourcePath()), 404, 'The file is missing.');

        $path = $filled->storeAs("documents/clients/{$document->policy_id}", Str::uuid().'.pdf', self::DISK);
        $old = $document->path;
        $base = $document->pdfSourcePath();

        $this->record($path, fn () => $document->update([
            'path' => $path,
            'base_path' => $base,
            'mime' => 'application/pdf',
            'size' => $disk->size($path),
            'pdf_fields' => $fields ?: null,
            'unfilled' => $this->unfilledPdfKeys($placeholderKeys, $document->policy, $by) ?: null,
            'edited_at' => now(),
        ]));
        if ($old !== $base) {
            $disk->delete($old);
        }

        AuditLogger::record('edited_content', 'client_documents', $document->id, null,
            ['fields' => count($fields), 'unfilled' => $document->unfilled ?? []],
            "Edited PDF \"{$document->name}\" in the PDF editor", $by->id);

        return $document;
    }

    /** Placeholders with no value on this record (stamped blank). */
    private function unfilledPdfKeys(array $keys, Policy $policy, User $by): array
    {
        $values = $this->variables($policy, $by);

        return array_values(array_filter($keys, fn (string $key) => ($values[$key] ?? '') === ''));
    }

    /** Delete a client document's files (current and PDF original) once the transaction has committed. */
    public function deleteClientFiles(ClientDocument $document): void
    {
        foreach (array_filter([$document->path, $document->base_path]) as $path) {
            $this->deleteFile($path);
        }
    }

    /**
     * Save the in-app editor's content: sanitize it, rebuild the .docx from it and
     * replace the stored file. The sanitized HTML is kept as the editor copy.
     */
    public function saveContent(ClientDocument $document, string $html, User $by): ClientDocument
    {
        $clean = DocumentHtml::sanitize($html);

        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(11);
        // A4 with 2 cm margins (twips).
        $section = $word->addSection(['pageSizeW' => 11906, 'pageSizeH' => 16838, 'marginTop' => 1134, 'marginBottom' => 1134, 'marginLeft' => 1134, 'marginRight' => 1134]);

        try {
            Html::addHtml($section, $clean === '' ? '<p></p>' : $clean, false, false);
        } catch (\Throwable $e) {
            report($e);
            throw new \RuntimeException('The document content could not be converted to Word.');
        }

        $disk = Storage::disk(self::DISK);
        $path = "documents/clients/{$document->policy_id}/".Str::uuid().'.docx';
        $disk->makeDirectory(dirname($path));
        IOFactory::createWriter($word, 'Word2007')->save($disk->path($path));

        $old = $document->path;
        $this->record($path, fn () => $document->update([
            'path' => $path,
            'extension' => 'docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'original_name' => Str::slug($document->name).'.docx',
            'size' => $disk->size($path),
            'content_html' => $clean,
            'unfilled' => DocumentHtml::placeholders($clean) ?: null,
            'edited_at' => now(),
        ]));
        if ($old !== $path) {
            $disk->delete($old);
        }

        AuditLogger::record('edited_content', 'client_documents', $document->id, null,
            ['size' => $document->size, 'unfilled' => $document->unfilled ?? []],
            "Edited document \"{$document->name}\" in the editor", $by->id);

        return $document;
    }

    /** Fields for the editor's "Insert field" / "Autofill": key, label and this record's value. */
    public function fields(Policy $policy, User $advisor): array
    {
        $values = $this->variables($policy, $advisor);

        return array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'value' => $values[$key] ?? ''], array_keys(self::PLACEHOLDERS), self::PLACEHOLDERS);
    }

    /** Delete the database row, then the file once the transaction has committed. */
    public function deleteFile(string $path): void
    {
        DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));
    }

    public function response(string $path, string $name, string $mime, bool $download): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($path), 404, 'The file is missing.');

        $headers = ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        return $download ? $disk->download($path, $name, $headers) : $disk->response($path, $name, $headers, 'inline');
    }

    /** @return array{0: string, 1: array{original_name: string, extension: string, mime: string, size: int}} */
    private function put(UploadedFile $file, string $directory): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? 'bin'));
        // Random name on disk; the client's filename is kept only as metadata.
        $path = $file->storeAs($directory, Str::uuid().'.'.$extension, self::DISK);

        return [$path, [
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 180, ''),
            'extension' => $extension,
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
        ]];
    }

    /** Run the DB write; if it fails, remove the file(s) that were just stored. */
    private function record(string|array $paths, callable $write): mixed
    {
        try {
            return $write();
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete(array_filter((array) $paths));
            throw $e;
        }
    }
}

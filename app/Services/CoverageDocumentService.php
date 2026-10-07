<?php

namespace App\Services;

use App\Models\Policy;
use App\Support\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores policy coverage documents on the private "local" disk
 * (storage/app/private). Files are never web-accessible directly; they
 * are streamed through authenticated API routes only.
 */
class CoverageDocumentService
{
    public const DISK = 'local';

    public const ALLOWED_MIMES = ['pdf', 'jpg', 'jpeg', 'png'];

    public const MAX_KB = 10240;

    public function store(Policy $policy, UploadedFile $file): Policy
    {
        $previousPath = $policy->insurance_coverage_path;
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        // Random name: the client's filename is kept only as metadata.
        $path = $file->storeAs("policies/{$policy->id}", 'coverage-'.Str::uuid().'.'.$extension, self::DISK);

        try {
            DB::transaction(function () use ($policy, $file, $path) {
                $policy->forceFill([
                    'insurance_coverage_path' => $path,
                    'coverage_original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                    'coverage_mime' => $file->getMimeType(),
                    'coverage_size' => $file->getSize(),
                    'coverage_uploaded_at' => now(),
                ])->saveQuietly();
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }

        // Only remove the old file once the new one is safely recorded.
        if ($previousPath && $previousPath !== $path) {
            Storage::disk(self::DISK)->delete($previousPath);
        }

        AuditLogger::record(
            $previousPath ? 'replaced_document' : 'uploaded_document',
            'policies',
            $policy->id,
            $previousPath ? ['file' => basename($previousPath)] : null,
            ['file' => $policy->coverage_original_name, 'size' => $policy->coverage_size, 'mime' => $policy->coverage_mime],
            ($previousPath ? 'Replaced' : 'Uploaded')." coverage document for policy {$policy->policy_number}",
        );

        return $policy;
    }

    public function delete(Policy $policy): void
    {
        $path = $policy->insurance_coverage_path;

        if (! $path) {
            return;
        }

        $name = $policy->coverage_original_name;

        $policy->forceFill([
            'insurance_coverage_path' => null,
            'coverage_original_name' => null,
            'coverage_mime' => null,
            'coverage_size' => null,
            'coverage_uploaded_at' => null,
        ])->saveQuietly();

        Storage::disk(self::DISK)->delete($path);

        AuditLogger::record('deleted_document', 'policies', $policy->id, ['file' => $name], null,
            "Deleted coverage document for policy {$policy->policy_number}");
    }

    public function response(Policy $policy, bool $download): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($policy->insurance_coverage_path && $disk->exists($policy->insurance_coverage_path), 404, 'No coverage document on file.');

        $name = $policy->coverage_original_name ?: basename($policy->insurance_coverage_path);
        $headers = [
            'Content-Type' => $policy->coverage_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];

        return $download
            ? $disk->download($policy->insurance_coverage_path, $name, $headers)
            : $disk->response($policy->insurance_coverage_path, $name, $headers, 'inline');
    }
}

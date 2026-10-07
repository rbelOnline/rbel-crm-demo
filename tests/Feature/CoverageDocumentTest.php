<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Policy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoverageDocumentTest extends TestCase
{
    public function test_upload_view_download_replace_and_delete(): void
    {
        Storage::fake('local');
        $this->signIn('admin');
        $policy = Policy::factory()->create();

        $this->post("/api/policies/{$policy->id}/document", [
            'document' => UploadedFile::fake()->create('coverage.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.coverage_document.name', 'coverage.pdf')
            ->assertJsonMissingPath('data.insurance_coverage_path');

        $first = $policy->fresh()->insurance_coverage_path;
        Storage::disk('local')->assertExists($first);
        $this->assertStringStartsWith("policies/{$policy->id}/coverage-", $first);

        $this->get("/api/policies/{$policy->id}/document")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get("/api/policies/{$policy->id}/document/download")->assertOk()->assertDownload('coverage.pdf');

        // Replace: the old file is removed.
        $this->post("/api/policies/{$policy->id}/document", [
            'document' => UploadedFile::fake()->image('scan.png'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.coverage_document.name', 'scan.png');
        Storage::disk('local')->assertMissing($first);

        $this->assertTrue(AuditLog::where('action', 'uploaded_document')->exists());
        $this->assertTrue(AuditLog::where('action', 'replaced_document')->exists());

        $this->deleteJson("/api/policies/{$policy->id}/document")->assertNoContent();
        $this->assertNull($policy->fresh()->insurance_coverage_path);
        $this->getJson("/api/policies/{$policy->id}/document")->assertNotFound();
    }

    public function test_rejects_bad_type_and_oversized_files(): void
    {
        Storage::fake('local');
        $this->signIn();
        $policy = Policy::factory()->create();

        $this->postJson("/api/policies/{$policy->id}/document", ['document' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php')])
            ->assertUnprocessable()->assertJsonValidationErrors('document');

        $this->postJson("/api/policies/{$policy->id}/document", ['document' => UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('document');

        $this->postJson("/api/policies/{$policy->id}/document", [])->assertUnprocessable();
    }

    public function test_documents_require_authentication_and_permission_to_delete(): void
    {
        Storage::fake('local');
        $policy = Policy::factory()->create();

        $this->getJson("/api/policies/{$policy->id}/document")->assertUnauthorized();
        $this->postJson("/api/policies/{$policy->id}/document", ['document' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertUnauthorized();

        $this->signIn('assistant');
        $this->deleteJson("/api/policies/{$policy->id}/document")->assertForbidden();
    }
}

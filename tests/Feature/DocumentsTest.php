<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ClientDocument;
use App\Models\DocumentTemplate;
use App\Models\Client;
use App\Models\Policy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DocumentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * A small .docx. "{{owner_full_name}}" is split over three runs the way Word does
     * after formatting; the header holds {{policy_number}}.
     */
    private function docx(string $name = 'form.docx'): UploadedFile
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $document = <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <w:document {$w}><w:body>
              <w:p><w:r><w:t xml:space="preserve">Owner: </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>{{owner_</w:t></w:r><w:r><w:t>full_</w:t></w:r><w:r><w:t>name}}</w:t></w:r><w:r><w:t xml:space="preserve"> (insured: {{insured_first_name}})</w:t></w:r></w:p>
              <w:p><w:r><w:t>Plan {{product}} / {{plan_type}} / sum {{sum_assured}} / {{unknown_field}}</w:t></w:r></w:p>
            </w:body></w:document>
            XML;
        $header = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><w:hdr {$w}><w:p><w:r><w:t>Ref {{policy_number}}</w:t></w:r></w:p></w:hdr>";

        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', trim($document));
        $zip->addFromString('word/header1.xml', $header);
        $zip->close();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    public function test_word_files_with_a_doctype_are_rejected(): void
    {
        $this->signIn('admin');
        // Entity-expansion ("billion laughs") payload: Word never writes a DOCTYPE.
        $bomb = '<?xml version="1.0"?><!DOCTYPE w [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&b; {{policy_number}}</w:t></w:r></w:p></w:body></w:document>';
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', $bomb);
        $zip->close();

        $file = new UploadedFile($path, 'bomb.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $this->post('/api/document-templates', ['name' => 'Bomb', 'file' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertDatabaseMissing('document_templates', ['name' => 'Bomb']);
    }

    private function textOf(string $docxBinary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'out');
        file_put_contents($path, $docxBinary);
        $zip = new ZipArchive;
        $zip->open($path);
        $text = strip_tags($zip->getFromName('word/document.xml')).' | '.strip_tags($zip->getFromName('word/header1.xml'));
        $zip->close();

        return html_entity_decode($text);
    }

    private function policy(): Policy
    {
        $owner = Client::factory()->create(['first_name' => 'Rosa', 'middle_name' => null, 'last_name' => 'Cruz & Sons', 'address' => '5 Rizal Ave.']);
        $insured = Client::factory()->create(['first_name' => 'Ben']);

        return Policy::factory()->ownedBy($owner)->insuring($insured)->create([
            'policy_number' => 'RB-DOC-0001', 'product_id' => $this->product()->id, 'sum_assured' => 1500000,
        ]);
    }

    public function test_template_crud_and_placeholder_detection(): void
    {
        $this->signIn('advisor');

        $data = $this->post('/api/document-templates', ['name' => 'Application Form', 'description' => 'New business', 'file' => $this->docx()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.fillable', true)
            ->assertJsonPath('data.extension', 'docx')
            ->json('data');

        // Split across runs and in the header: still detected.
        $this->assertEqualsCanonicalizing(['owner_full_name', 'insured_first_name', 'product', 'plan_type', 'sum_assured', 'unknown_field', 'policy_number'], $data['placeholders']);

        $this->getJson('/api/document-templates?search=Application')->assertOk()->assertJsonCount(1, 'data')->assertJsonStructure(['placeholders']);
        $this->get("/api/document-templates/{$data['id']}/download")->assertOk()->assertDownload('form.docx');

        // Rename without a new file; then replace the file.
        $this->put("/api/document-templates/{$data['id']}", ['name' => 'Application Form v2'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.name', 'Application Form v2');
        $oldPath = DocumentTemplate::find($data['id'])->path;
        $this->post("/api/document-templates/{$data['id']}", ['_method' => 'PUT', 'name' => 'Application Form v2', 'file' => UploadedFile::fake()->create('v2.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.fillable', false)->assertJsonPath('data.placeholders', []);
        Storage::disk('local')->assertMissing($oldPath);

        $this->deleteJson("/api/document-templates/{$data['id']}")->assertNoContent();
        $this->assertSame(0, DocumentTemplate::count());
    }

    public function test_template_validation_and_permissions(): void
    {
        $this->signIn('advisor');
        $this->post('/api/document-templates', ['name' => '', 'file' => UploadedFile::fake()->create('x.exe', 5, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'file']);
        $this->post('/api/document-templates', ['name' => 'No file'], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->signIn('assistant');
        $this->getJson('/api/document-templates')->assertOk();
        $this->post('/api/document-templates', ['name' => 'X', 'file' => $this->docx()], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_client_document_from_a_docx_template_is_filled_with_the_record_details(): void
    {
        $this->signIn('advisor');
        $policy = $this->policy();
        $templateId = $this->post('/api/document-templates', ['name' => 'Application Form', 'file' => $this->docx()], ['Accept' => 'application/json'])->json('data.id');

        $doc = $this->postJson("/api/policies/{$policy->id}/documents", ['source' => 'template', 'document_template_id' => $templateId])
            ->assertCreated()
            ->assertJsonPath('data.source', 'template')
            ->assertJsonPath('data.name', 'Application Form')
            ->assertJsonPath('data.template.name', 'Application Form')
            ->assertJsonPath('data.unfilled', ['unknown_field'])
            ->json('data');

        $text = $this->textOf($this->get("/api/policies/{$policy->id}/documents/{$doc['id']}/download")->assertOk()->streamedContent());

        $this->assertStringContainsString('Owner: Rosa Cruz & Sons (insured: Ben)', $text); // split runs joined, "&" escaped safely
        $this->assertStringContainsString('Plan RBEL Life Protect 20 / TRAD / sum ₱1,500,000.00 / {{unknown_field}}', $text);
        $this->assertStringContainsString('Ref RB-DOC-0001', $text); // header filled
        $this->assertStringNotContainsString('{{owner_', $text);
    }

    public function test_non_docx_templates_are_copied_and_own_files_can_be_uploaded_renamed_replaced_and_deleted(): void
    {
        $this->signIn('advisor');
        $policy = $this->policy();
        $pdfTemplate = $this->post('/api/document-templates', ['name' => 'Brochure', 'file' => UploadedFile::fake()->createWithContent('brochure.pdf', '%PDF-1.4 brochure')], ['Accept' => 'application/json'])->json('data.id');

        $copy = $this->postJson("/api/policies/{$policy->id}/documents", ['source' => 'template', 'document_template_id' => $pdfTemplate, 'name' => 'Client brochure'])
            ->assertCreated()->assertJsonPath('data.name', 'Client brochure')->assertJsonPath('data.unfilled', [])->json('data');
        $this->assertSame('%PDF-1.4 brochure', $this->get("/api/policies/{$policy->id}/documents/{$copy['id']}/download")->streamedContent());

        $upload = $this->post("/api/policies/{$policy->id}/documents", ['source' => 'upload', 'file' => UploadedFile::fake()->create('signed-form.pdf', 50, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.source', 'upload')->assertJsonPath('data.name', 'signed-form')->json('data');

        $this->getJson("/api/policies/{$policy->id}/documents")->assertOk()->assertJsonCount(2, 'data');

        $this->putJson("/api/policies/{$policy->id}/documents/{$upload['id']}", ['name' => 'Signed application'])->assertOk()->assertJsonPath('data.name', 'Signed application');
        $old = ClientDocument::find($upload['id'])->path;
        $this->post("/api/policies/{$policy->id}/documents/{$upload['id']}", ['_method' => 'PUT', 'name' => 'Signed application', 'file' => UploadedFile::fake()->image('scan.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.extension', 'jpg');
        Storage::disk('local')->assertMissing($old);

        // Scoped: a document is only reachable through its own client record.
        $other = Policy::factory()->selfInsured(Client::factory()->create())->create(['product_id' => $this->product()->id]);
        $this->getJson("/api/policies/{$other->id}/documents/{$upload['id']}/download")->assertNotFound();

        // Deleting needs manage rights.
        $this->signIn('assistant');
        $this->deleteJson("/api/policies/{$policy->id}/documents/{$upload['id']}")->assertForbidden();
        $this->signIn('advisor');
        $path = ClientDocument::find($upload['id'])->path;
        $this->deleteJson("/api/policies/{$policy->id}/documents/{$upload['id']}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_inactive_templates_cannot_be_used_and_deleting_a_template_keeps_client_documents(): void
    {
        $this->signIn('admin');
        $policy = $this->policy();
        $templateId = $this->post('/api/document-templates', ['name' => 'Form', 'file' => $this->docx()], ['Accept' => 'application/json'])->json('data.id');
        $doc = $this->postJson("/api/policies/{$policy->id}/documents", ['source' => 'template', 'document_template_id' => $templateId])->json('data');

        DocumentTemplate::whereKey($templateId)->update(['is_active' => false]);
        $this->postJson("/api/policies/{$policy->id}/documents", ['source' => 'template', 'document_template_id' => $templateId])
            ->assertUnprocessable()->assertJsonValidationErrors('document_template_id');

        $this->deleteJson("/api/document-templates/{$templateId}")->assertNoContent();
        $kept = ClientDocument::findOrFail($doc['id']);
        $this->assertNull($kept->document_template_id);
        Storage::disk('local')->assertExists($kept->path);
    }

    public function test_editor_fields_give_this_records_values_for_autofill(): void
    {
        $this->signIn('advisor');
        $policy = $this->policy();

        $fields = collect($this->getJson("/api/policies/{$policy->id}/documents/fields")->assertOk()->json('data'))->keyBy('key');

        $this->assertSame('Rosa Cruz & Sons', $fields['owner_full_name']['value']);
        $this->assertSame('Policy Owner: full name', $fields['owner_full_name']['label']);
        $this->assertSame('Ben', $fields['insured_first_name']['value']);
        $this->assertSame('RB-DOC-0001', $fields['policy_number']['value']);
        $this->assertSame('5 Rizal Ave.', $fields['owner_address']['value']);
    }

    public function test_saving_editor_content_sanitizes_it_and_rebuilds_the_word_file(): void
    {
        $this->signIn('advisor');
        $policy = $this->policy();
        $templateId = $this->post('/api/document-templates', ['name' => 'Form', 'file' => $this->docx()], ['Accept' => 'application/json'])->json('data.id');
        $doc = $this->postJson("/api/policies/{$policy->id}/documents", ['source' => 'template', 'document_template_id' => $templateId])->json('data');
        $oldPath = ClientDocument::find($doc['id'])->path;

        // Not edited yet: the browser opens the .docx itself.
        $this->getJson("/api/policies/{$policy->id}/documents/{$doc['id']}/content")->assertOk()->assertJsonPath('data.editable', true)->assertJsonPath('data.html', null);

        $html = '<h2 style="text-align: center; position: fixed">Client Form</h2>'
            .'<p onclick="alert(1)">Owner: <strong>Rosa Cruz &amp; Sons</strong><script>alert(1)</script></p>'
            .'<p>Address: {{owner_address}}</p>'
            .'<p><img src="https://evil.test/track.png"><a href="javascript:alert(1)">link text</a></p>'
            .'<table><tbody><tr><td colspan="2">Cell</td></tr></tbody></table>';

        $saved = $this->putJson("/api/policies/{$policy->id}/documents/{$doc['id']}/content", ['html' => $html])
            ->assertOk()
            ->assertJsonPath('data.unfilled', ['owner_address'])
            ->assertJsonPath('data.extension', 'docx')
            ->json('data');

        $clean = $saved['html'];
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('evil.test', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('position', $clean);
        $this->assertStringContainsString('text-align: center', $clean);
        $this->assertStringContainsString('link text', $clean); // link removed, its text kept
        $this->assertStringContainsString('colspan="2"', $clean);
        $this->assertNotNull($saved['edited_at']);

        // A new, real .docx replaced the old file and contains the edited text.
        Storage::disk('local')->assertMissing($oldPath);
        $binary = $this->get("/api/policies/{$policy->id}/documents/{$doc['id']}/download")->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'ed');
        file_put_contents($path, $binary);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $text = html_entity_decode(strip_tags($zip->getFromName('word/document.xml')));
        $zip->close();
        $this->assertStringContainsString('Client Form', $text);
        $this->assertStringContainsString('Owner: Rosa Cruz & Sons', $text);

        // Reopening uses the saved editor copy.
        $this->getJson("/api/policies/{$policy->id}/documents/{$doc['id']}/content")->assertJsonPath('data.html', $clean);
    }

    public function test_only_word_documents_can_be_edited_and_replacing_the_file_resets_the_editor_copy(): void
    {
        $this->signIn('advisor');
        $policy = $this->policy();
        $pdf = $this->post("/api/policies/{$policy->id}/documents", ['source' => 'upload', 'file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->json('data');
        $this->assertFalse($pdf['editable']);
        $this->putJson("/api/policies/{$policy->id}/documents/{$pdf['id']}/content", ['html' => '<p>x</p>'])->assertUnprocessable();

        $word = $this->post("/api/policies/{$policy->id}/documents", ['source' => 'upload', 'file' => $this->docx('mine.docx')], ['Accept' => 'application/json'])->json('data');
        $this->putJson("/api/policies/{$policy->id}/documents/{$word['id']}/content", ['html' => '<p>Edited</p>'])->assertOk();
        $this->post("/api/policies/{$policy->id}/documents/{$word['id']}", ['_method' => 'PUT', 'name' => 'Mine', 'file' => $this->docx('signed.docx')], ['Accept' => 'application/json'])->assertOk();

        $this->getJson("/api/policies/{$policy->id}/documents/{$word['id']}/content")->assertJsonPath('data.html', null);
    }

    public function test_deleting_a_client_record_deletes_its_documents_and_files(): void
    {
        $this->signIn('admin');
        $policy = $this->policy();
        $doc = $this->post("/api/policies/{$policy->id}/documents", ['source' => 'upload', 'file' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->json('data');
        $path = ClientDocument::find($doc['id'])->path;

        $this->deleteJson("/api/policies/{$policy->id}")->assertNoContent();

        $this->assertNull(ClientDocument::find($doc['id']));
        Storage::disk('local')->assertMissing($path);
    }

    private function pdf(string $name = 'form.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    private function pdfField(array $overrides = []): array
    {
        return $overrides + ['key' => 'owner_full_name', 'page' => 1, 'x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.02, 'size' => 10, 'align' => 'left'];
    }

    public function test_placeholders_can_be_positioned_on_a_pdf_template(): void
    {
        $this->signIn('advisor');
        $pdf = $this->post('/api/document-templates', ['name' => 'Claim Form', 'file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.fillable', false)
            ->assertJsonPath('data.pdf_fields', [])
            ->json('data');

        $this->putJson("/api/document-templates/{$pdf['id']}/pdf-fields", ['fields' => [
            $this->pdfField(),
            $this->pdfField(['key' => 'policy_number', 'page' => 2, 'align' => 'right']),
            $this->pdfField(['y' => 0.5]),
        ]])
            ->assertOk()
            ->assertJsonPath('data.fillable', true)
            ->assertJsonPath('data.placeholders', ['owner_full_name', 'policy_number'])
            ->assertJsonCount(3, 'data.pdf_fields')
            ->assertJsonPath('data.pdf_fields.1.align', 'right')
            ->assertJsonStructure(['placeholders' => ['owner_full_name']]);

        $this->getJson('/api/document-templates?kind=fillable')->assertJsonPath('data.0.id', $pdf['id'])->assertJsonMissingPath('data.0.pdf_fields');
        $this->getJson('/api/document-templates?kind=other')->assertJsonCount(0, 'data');

        // Unknown placeholders and out-of-page boxes are rejected; Word templates have no PDF fields.
        $this->putJson("/api/document-templates/{$pdf['id']}/pdf-fields", ['fields' => [$this->pdfField(['key' => 'password'])]])->assertJsonValidationErrors('fields.0.key');
        $this->putJson("/api/document-templates/{$pdf['id']}/pdf-fields", ['fields' => [$this->pdfField(['x' => 1.5])]])->assertJsonValidationErrors('fields.0.x');
        $word = $this->post('/api/document-templates', ['name' => 'Letter', 'file' => $this->docx()], ['Accept' => 'application/json'])->json('data');
        $this->putJson("/api/document-templates/{$word['id']}/pdf-fields", ['fields' => []])->assertJsonValidationErrors('fields');

        // Clearing all fields makes it a copied-as-is PDF again.
        $this->putJson("/api/document-templates/{$pdf['id']}/pdf-fields", ['fields' => []])->assertOk()->assertJsonPath('data.fillable', false)->assertJsonPath('data.placeholders', []);

        $this->signIn('assistant');
        $this->putJson("/api/document-templates/{$pdf['id']}/pdf-fields", ['fields' => [$this->pdfField()]])->assertForbidden();
    }

    public function test_replacing_a_pdf_template_keeps_its_placeholders_and_another_file_type_drops_them(): void
    {
        $this->signIn('advisor');
        $t = $this->post('/api/document-templates', ['name' => 'Claim Form', 'file' => $this->pdf()], ['Accept' => 'application/json'])->json('data');
        $this->putJson("/api/document-templates/{$t['id']}/pdf-fields", ['fields' => [$this->pdfField()]])->assertOk();

        $this->post("/api/document-templates/{$t['id']}", ['_method' => 'PUT', 'name' => 'Claim Form', 'file' => $this->pdf('v2.pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.placeholders', ['owner_full_name'])->assertJsonCount(1, 'data.pdf_fields');

        $this->post("/api/document-templates/{$t['id']}", ['_method' => 'PUT', 'name' => 'Claim Form', 'file' => $this->docx()], ['Accept' => 'application/json'])->assertOk();
        $this->assertNull(DocumentTemplate::find($t['id'])->pdf_fields);
    }

    public function test_client_document_from_a_pdf_template_stores_the_filled_copy_from_the_browser(): void
    {
        $this->signIn('advisor');
        $policy = $this->policy();
        $policy->owner->update(['email' => null]);
        $t = $this->post('/api/document-templates', ['name' => 'Claim Form', 'file' => $this->pdf()], ['Accept' => 'application/json'])->json('data');
        $this->putJson("/api/document-templates/{$t['id']}/pdf-fields", ['fields' => [$this->pdfField(), $this->pdfField(['key' => 'owner_email'])]])->assertOk();

        // The browser must send the filled PDF.
        $this->post("/api/policies/{$policy->id}/documents", ['source' => 'template', 'document_template_id' => $t['id']], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
        $this->post("/api/policies/{$policy->id}/documents", ['source' => 'template', 'document_template_id' => $t['id'], 'file' => $this->docx()], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');

        $doc = $this->post("/api/policies/{$policy->id}/documents", ['source' => 'template', 'document_template_id' => $t['id'], 'file' => $this->pdf('filled.pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'template')
            ->assertJsonPath('data.template.id', $t['id'])
            ->assertJsonPath('data.extension', 'pdf')
            ->assertJsonPath('data.file_name', 'claim-form-rb-doc-0001.pdf')
            ->assertJsonPath('data.unfilled', ['owner_email'])
            ->json('data');

        $stored = ClientDocument::find($doc['id']);
        Storage::disk('local')->assertExists($stored->path);
        $this->assertNotSame(DocumentTemplate::find($t['id'])->path, $stored->path);
    }

    public function test_a_client_pdf_is_filled_in_the_pdf_editor_from_its_unfilled_original(): void
    {
        $this->signIn('assistant');
        $policy = $this->policy();
        $policy->owner->update(['email' => null]);
        $doc = $this->post("/api/policies/{$policy->id}/documents", ['source' => 'upload', 'file' => $this->pdf('claim.pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.editor', 'pdf')->assertJsonPath('data.pdf_fields', [])->json('data');
        $original = ClientDocument::find($doc['id'])->path;
        $url = "/api/policies/{$policy->id}/documents/{$doc['id']}";
        $fields = json_encode([$this->pdfField(), $this->pdfField(['key' => 'owner_email']), $this->pdfField(['key' => 'text', 'text' => 'X', 'y' => 0.6])]);

        // First save: the uploaded file becomes the original; the stamped copy replaces the file.
        $this->post("{$url}/pdf", ['_method' => 'PUT', 'fields' => $fields, 'file' => $this->pdf('filled.pdf')], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(3, 'data.pdf_fields')
            ->assertJsonPath('data.pdf_fields.2.text', 'X')
            ->assertJsonPath('data.unfilled', ['owner_email']);
        $first = ClientDocument::find($doc['id']);
        $this->assertSame($original, $first->base_path);
        $this->assertNotSame($original, $first->path);
        $this->assertNotNull($first->edited_at);

        // The editor always starts from the original; later saves replace only the stamped copy.
        $this->get("{$url}/pdf-source")->assertOk();
        $this->post("{$url}/pdf", ['_method' => 'PUT', 'fields' => '[]', 'file' => $this->pdf('again.pdf')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.unfilled', []);
        $second = ClientDocument::find($doc['id']);
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertExists($original);
        $this->assertSame($original, $second->base_path);

        // Validation: text boxes need text; the stamped PDF is required; only PDFs.
        $this->post("{$url}/pdf", ['_method' => 'PUT', 'fields' => json_encode([$this->pdfField(['key' => 'text'])]), 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertJsonValidationErrors('fields.0.text');
        $this->post("{$url}/pdf", ['_method' => 'PUT', 'fields' => '[]'], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
        $this->post("{$url}/pdf", ['_method' => 'PUT', 'fields' => '{oops', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertJsonValidationErrors('fields');
        $word = $this->post("/api/policies/{$policy->id}/documents", ['source' => 'upload', 'file' => $this->docx()], ['Accept' => 'application/json'])->assertJsonPath('data.editor', 'word')->json('data');
        $this->post("/api/policies/{$policy->id}/documents/{$word['id']}/pdf", ['_method' => 'PUT', 'fields' => '[]', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertJsonValidationErrors('fields');
        $this->get("/api/policies/{$policy->id}/documents/{$word['id']}/pdf-source", ['Accept' => 'application/json'])->assertStatus(422);

        // Replacing the file starts over; deleting removes both files.
        $this->post($url, ['_method' => 'PUT', 'name' => 'Claim', 'file' => $this->pdf('signed.pdf')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.pdf_fields', []);
        Storage::disk('local')->assertMissing($original);
        $this->post("{$url}/pdf", ['_method' => 'PUT', 'fields' => $fields, 'file' => $this->pdf('filled.pdf')], ['Accept' => 'application/json'])->assertOk();
        $last = ClientDocument::find($doc['id']);
        $this->signIn('advisor');
        $this->deleteJson($url)->assertNoContent();
        Storage::disk('local')->assertMissing($last->path);
        Storage::disk('local')->assertMissing($last->base_path);
    }

    public function test_a_client_pdf_made_from_a_template_keeps_the_template_fields_and_original(): void
    {
        $this->signIn('advisor');
        $policy = $this->policy();
        $t = $this->post('/api/document-templates', ['name' => 'Claim Form', 'file' => $this->pdf()], ['Accept' => 'application/json'])->json('data');
        $this->putJson("/api/document-templates/{$t['id']}/pdf-fields", ['fields' => [$this->pdfField(), $this->pdfField(['key' => 'text', 'text' => 'Approved'])]])
            ->assertOk()->assertJsonPath('data.placeholders', ['owner_full_name']);

        $doc = $this->post("/api/policies/{$policy->id}/documents", ['source' => 'template', 'document_template_id' => $t['id'], 'file' => $this->pdf('filled.pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(2, 'data.pdf_fields')->assertJsonPath('data.pdf_fields.1.text', 'Approved')->json('data');

        $stored = ClientDocument::find($doc['id']);
        Storage::disk('local')->assertExists($stored->base_path);
        $this->assertNotSame($stored->path, $stored->base_path);
        $this->assertSame(Storage::disk('local')->get(DocumentTemplate::find($t['id'])->path), Storage::disk('local')->get($stored->base_path));
    }

    public function test_images_can_be_placed_on_pdfs_and_are_kept_out_of_the_audit_trail(): void
    {
        $this->signIn('advisor');
        $png = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $t = $this->post('/api/document-templates', ['name' => 'Claim Form', 'file' => $this->pdf()], ['Accept' => 'application/json'])->json('data');

        $this->putJson("/api/document-templates/{$t['id']}/pdf-fields", ['fields' => [$this->pdfField(['key' => 'image', 'src' => $png])]])
            ->assertOk()
            ->assertJsonPath('data.pdf_fields.0.src', $png)
            ->assertJsonPath('data.placeholders', [])
            ->assertJsonPath('data.fillable', true);

        $audit = AuditLog::where('module', 'document_templates')->where('record_id', $t['id'])->latest('id')->first();
        $this->assertStringNotContainsString('base64', json_encode([$audit->old_values, $audit->new_values]));
        $this->assertStringContainsString('[image]', json_encode($audit->new_values));

        // Only PNG/JPEG data URLs; an image box needs an image; at most 10 images.
        $url = "/api/document-templates/{$t['id']}/pdf-fields";
        $this->putJson($url, ['fields' => [$this->pdfField(['key' => 'image', 'src' => 'https://example.com/a.png'])]])->assertJsonValidationErrors('fields.0.src');
        $this->putJson($url, ['fields' => [$this->pdfField(['key' => 'image', 'src' => 'data:image/svg+xml;base64,PHN2Zz4='])]])->assertJsonValidationErrors('fields.0.src');
        $this->putJson($url, ['fields' => [$this->pdfField(['key' => 'image'])]])->assertJsonValidationErrors('fields.0.src');
        $this->putJson($url, ['fields' => array_fill(0, 11, $this->pdfField(['key' => 'image', 'src' => $png]))])->assertJsonValidationErrors('fields');

        // Client PDFs accept images too.
        $policy = $this->policy();
        $doc = $this->post("/api/policies/{$policy->id}/documents", ['source' => 'upload', 'file' => $this->pdf('claim.pdf')], ['Accept' => 'application/json'])->json('data');
        $this->post("/api/policies/{$policy->id}/documents/{$doc['id']}/pdf", ['_method' => 'PUT', 'fields' => json_encode([$this->pdfField(['key' => 'image', 'src' => $png])]), 'file' => $this->pdf('filled.pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.pdf_fields.0.src', $png)->assertJsonPath('data.unfilled', []);
    }
}

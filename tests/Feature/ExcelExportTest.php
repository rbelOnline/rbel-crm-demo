<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Lead;
use App\Services\ExcelExport;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class ExcelExportTest extends TestCase
{
    /** @return list<list<mixed>> every row of the first sheet, header included */
    private function sheet(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());

        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        unlink($path);

        return $rows;
    }

    public function test_leads_export_is_xlsx_with_only_filtered_leads(): void
    {
        $this->signIn();
        $this->ownerInsuredScenario(); // clients: not in the leads export
        Lead::factory()->create(['first_name' => 'Lara', 'last_name' => 'Aquino', 'email' => 'lara@example.test']);
        Lead::factory()->create(['first_name' => 'Nico', 'last_name' => 'Bautista']);

        $response = $this->get('/api/leads/export?search=Lara')->assertOk();
        $this->assertSame(ExcelExport::MIME, $response->headers->get('Content-Type'));
        $this->assertStringContainsString('leads-'.today()->toDateString().'.xlsx', $response->headers->get('Content-Disposition'));

        $rows = $this->sheet($response);
        $this->assertSame(['Last name', 'First name'], array_slice($rows[0], 0, 2));
        $this->assertCount(2, $rows); // header + Lara
        $this->assertSame(['Aquino', 'Lara'], array_slice($rows[1], 0, 2));
        $this->assertSame('lara@example.test', $rows[1][3]);

        $this->assertTrue(AuditLog::where(['module' => 'leads', 'action' => 'exported'])->exists());
    }

    public function test_clients_export_has_owner_and_insured_columns_and_respects_filters(): void
    {
        $this->signIn();
        ['juan' => $juan, 'maria' => $maria] = $this->ownerInsuredScenario();

        $rows = $this->sheet($this->get('/api/policies/export?sort=policy_number&direction=asc')->assertOk());

        $this->assertSame(['Policy No.', 'Policy Owner', 'Owner Email', 'Owner Mobile', 'Policy Insured'], array_slice($rows[0], 0, 5));
        $this->assertSame(['RB-A-0001', 'RB-B-0002', 'RB-C-0003'], array_column(array_slice($rows, 1), 0));
        // Policy A: Juan owns, Maria insured — two separate columns.
        $this->assertSame($juan->fresh()->displayName(), $rows[1][1]);
        $this->assertSame($maria->fresh()->displayName(), $rows[1][4]);
        // APE is a real number, dates are real dates.
        $this->assertEquals(10000, $rows[1][6]);
        $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][11]);

        // Same filters as the list: only RB-C issued in February.
        $year = now()->year;
        $filtered = $this->sheet($this->get("/api/policies/export?issued_from={$year}-02-01&issued_to={$year}-02-28")->assertOk());
        $this->assertSame(['RB-C-0003'], array_column(array_slice($filtered, 1), 0));

        $this->assertTrue(AuditLog::where(['module' => 'policies', 'action' => 'exported'])->exists());
    }

    public function test_text_that_looks_like_a_formula_is_exported_as_plain_text(): void
    {
        $this->signIn();
        Client::factory()->create(['first_name' => '=HYPERLINK("http://evil.test","x")', 'last_name' => 'Aquino']);

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $this->get('/api/clients/export')->assertOk()->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $this->assertStringNotContainsString('<f>', $xml); // no formula cells at all
        // Saved in Title Case, but still exported as text, not a formula.
        $this->assertStringContainsString('=Hyperlink(&quot;Http://Evil.test&quot;,&quot;X&quot;)', $xml);
    }

    public function test_export_requires_authentication(): void
    {
        $this->getJson('/api/clients/export')->assertUnauthorized();
        $this->getJson('/api/policies/export')->assertUnauthorized();
    }
}

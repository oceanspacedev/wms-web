<?php

namespace Tests\Feature;

use App\Jobs\ProcessCsaImportJob;
use App\Jobs\SyncToGoogleSheetJob;
use App\Models\CsaImport;
use App\Models\User;
use App\Services\CsaExcelParserService;
use App\Services\GoogleSheetWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class CsaBackgroundProcessingTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_URL = 'https://script.google.com/macros/s/test/exec';

    public function test_processing_job_queues_sheet_sync_instead_of_running_it(): void
    {
        Queue::fake();
        $import = $this->createImport(['status' => 'pending']);

        $parser = $this->createMock(CsaExcelParserService::class);
        $parser->method('parseAndAggregate')->willReturn([
            'total_raw_rows' => 2,
            'total_shipments' => 1,
            'by_sheet' => ['BANDUNG' => ['total_sj' => 1, 'total_qty' => 2, 'total_nominal' => 1000000.0]],
            'shipments' => [$this->parsedShipment('2610000001', 'BANDUNG')],
        ]);

        (new ProcessCsaImportJob($import, true))->handle($parser);

        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(1, $import->shipments()->count());
        Queue::assertPushed(SyncToGoogleSheetJob::class, fn (SyncToGoogleSheetJob $job): bool => $job->csaImport->is($import));
    }

    public function test_failed_processing_job_does_not_leave_import_stuck_in_processing(): void
    {
        $import = $this->createImport(['status' => 'processing']);

        (new ProcessCsaImportJob($import))->failed(new RuntimeException('Job timed out'));

        $import->refresh();
        $this->assertSame('failed', $import->status);
        $this->assertStringContainsString('Job timed out', $import->error_message);
    }

    public function test_sheet_sync_sends_one_batch_and_queues_the_rest(): void
    {
        Queue::fake();
        $this->fakeWebhook();
        $import = $this->createImport();
        $this->insertShipments($import, 'BANDUNG', SyncToGoogleSheetJob::BATCH_SIZE + 50);

        (new SyncToGoogleSheetJob($import))->handle(new GoogleSheetWebhookService(self::WEBHOOK_URL));

        $this->assertSame(SyncToGoogleSheetJob::BATCH_SIZE, $import->shipments()->where('is_synced', true)->count());
        $this->assertSame(SyncToGoogleSheetJob::BATCH_SIZE, $import->fresh()->total_synced);
        Queue::assertPushed(SyncToGoogleSheetJob::class, fn (SyncToGoogleSheetJob $job): bool => $job->resetSheets === ['BANDUNG']);
    }

    public function test_continuation_batch_does_not_reset_highlight_again_for_sheets_already_reset(): void
    {
        Queue::fake();
        $this->fakeWebhook();
        $import = $this->createImport();
        $this->insertShipments($import, 'BANDUNG', 3);
        $this->insertShipments($import, 'CIREBON', 3);

        (new SyncToGoogleSheetJob($import, resetSheets: ['BANDUNG']))->handle(new GoogleSheetWebhookService(self::WEBHOOK_URL));

        $resetFlags = Http::recorded()
            ->mapWithKeys(fn (array $pair) => [$pair[0]->data()['sheetName'] => $pair[0]->data()['resetHighlight']])
            ->all();

        $this->assertSame(['BANDUNG' => false, 'CIREBON' => true], $resetFlags);
        $this->assertSame(6, $import->shipments()->where('is_synced', true)->count());
        Queue::assertNothingPushed();
    }

    public function test_sheet_sync_stops_when_a_batch_makes_no_progress(): void
    {
        Queue::fake();
        Http::fake([
            'https://script.google.com/*' => Http::response(['status' => 'error', 'message' => 'Server sheet sedang sibuk'], 200),
        ]);
        $import = $this->createImport();
        $this->insertShipments($import, 'BANDUNG', SyncToGoogleSheetJob::BATCH_SIZE + 50);

        (new SyncToGoogleSheetJob($import))->handle(new GoogleSheetWebhookService(self::WEBHOOK_URL));

        $this->assertSame(0, $import->shipments()->where('is_synced', true)->count());
        Queue::assertNotPushed(SyncToGoogleSheetJob::class);
    }

    private function fakeWebhook(): void
    {
        Http::fake(fn (Request $request) => Http::response([
            'status' => 'success',
            'inserted' => count($request->data()['rows']),
            'skipped_indexes' => [],
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createImport(array $attributes = []): CsaImport
    {
        return CsaImport::create([
            'user_id' => User::factory()->create()->id,
            'file_name' => 'test.xlsx',
            'file_path' => '/tmp/test.xlsx',
            'status' => 'completed',
            ...$attributes,
        ]);
    }

    private function insertShipments(CsaImport $import, string $sheet, int $count): void
    {
        $now = now();

        foreach (array_chunk(range(1, $count), 500) as $numbers) {
            DB::table('csa_shipments')->insert(array_map(fn (int $i): array => [
                'csa_import_id' => $import->id,
                'no_sj' => "{$sheet}-{$i}",
                'kode_gudang' => 'TEST',
                'target_sheet' => $sheet,
                'tujuan_dealer' => 'TOKO TEST',
                'total_nominal_sj' => 1000000,
                'qty_unit' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], $numbers));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedShipment(string $noSj, string $sheet): array
    {
        return [
            'no_sj' => $noSj,
            'no_trans' => $noSj,
            'no_so' => null,
            'tanggal_order' => '2026-10-09',
            'tanggal_kirim' => '2026-10-09',
            'badan_usaha' => 'PT. MEDIA SELULAR INDONESIA',
            'kode_gudang' => 'GMBDG',
            'nama_gudang' => 'GUDANG BANDUNG',
            'target_sheet' => $sheet,
            'tujuan_dealer' => 'TOKO TEST',
            'alamat_kirim' => null,
            'nama_kota' => null,
            'brand' => 'ITEL',
            'reff_note' => null,
            'total_nominal_sj' => 1000000,
            'qty_unit' => 2,
            'qty_koli' => 1,
            'berat' => 1,
            'ketentuan_biaya_kirim' => 'INVOICE',
            'nama_ekspedisi' => null,
            'no_resi_awb' => null,
            'biaya_kirim' => null,
            'status_pembayaran' => 'TAGIHAN BULANAN',
            'status_pengiriman' => null,
            'ket_isi_unit' => '2x ITEL',
        ];
    }
}

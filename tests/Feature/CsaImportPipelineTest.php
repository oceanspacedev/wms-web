<?php

namespace Tests\Feature;

use App\Models\CsaImport;
use App\Models\CsaShipment;
use App\Models\User;
use App\Services\GoogleSheetWebhookService;
use Database\Seeders\WarehouseMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CsaImportPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WarehouseMappingSeeder::class);
    }

    public function test_warehouse_mappings_are_seeded(): void
    {
        $this->assertDatabaseHas('warehouse_mappings', [
            'csa_code' => 'GMCRB',
            'target_sheet' => 'CIREBON',
        ]);

        $this->assertDatabaseHas('warehouse_mappings', [
            'csa_code' => 'GMBDG',
            'target_sheet' => 'BANDUNG',
        ]);
    }

    public function test_csa_shipment_transformation_matches_google_sheet_columns(): void
    {
        $user = User::factory()->create();

        $import = CsaImport::create([
            'user_id' => $user->id,
            'file_name' => 'test.xlsx',
            'file_path' => '/tmp/test.xlsx',
            'status' => 'completed',
        ]);

        $shipment = CsaShipment::create([
            'csa_import_id' => $import->id,
            'no_sj' => 'SJ-202609001',
            'no_trans' => 'TRX-1001',
            'no_so' => 'SO-500',
            'tanggal_order' => '2026-09-01',
            'tanggal_kirim' => '2026-09-02',
            'badan_usaha' => 'PT. MEDIA SELULAR INDONESIA',
            'kode_gudang' => 'GMCRB',
            'nama_gudang' => 'GUDANG MSIS CIREBON',
            'target_sheet' => 'CIREBON',
            'tujuan_dealer' => 'ATLANTIC CELL',
            'alamat_kirim' => 'Jl. Tuparev',
            'nama_kota' => 'CIREBON',
            'brand' => 'REALME',
            'reff_note' => 'REALME - DP CIREBON',
            'total_nominal_sj' => 15000000.00,
            'qty_unit' => 10,
            'qty_koli' => 1,
            'berat' => 5.0,
            'ketentuan_biaya_kirim' => 'INVOICE',
            'nama_ekspedisi' => 'J&T',
            'no_resi_awb' => '1350082279',
            'biaya_kirim' => 50000.00,
            'status_pembayaran' => 'TAGIHAN BULANAN',
            'status_pengiriman' => 'ATLANTIC CELL',
            'ket_isi_unit' => '10x REALME',
        ]);

        $service = new GoogleSheetWebhookService;
        $row = $service->transformShipmentToRow($shipment);

        // Verify exactly 25 columns
        $this->assertCount(25, $row);

        // Verify key mapped fields
        $this->assertEquals('01/09/2026', $row[0]); // Tgl Order
        $this->assertEquals('02/09/2026', $row[1]); // Tgl Kirim
        $this->assertEquals('PT. MEDIA SELULAR INDONESIA', $row[2]); // Badan Usaha
        $this->assertEquals('CIREBON', $row[3]); // Target Sheet / Depo
        $this->assertEquals('ATLANTIC CELL', $row[4]); // Tujuan / Dealer
        $this->assertEquals('SJ-202609001', $row[8]); // Nomor SJ
        $this->assertEquals(15000000.0, $row[9]); // Nominal
        $this->assertEquals(10, $row[11]); // Qty Unit
        $this->assertEquals('J&T', $row[15]); // Ekspedisi
        $this->assertEquals('1350082279', $row[16]); // Resi
    }

    public function test_google_sheet_webhook_sync(): void
    {
        $user = User::factory()->create();

        $import = CsaImport::create([
            'user_id' => $user->id,
            'file_name' => 'test.xlsx',
            'file_path' => '/tmp/test.xlsx',
            'status' => 'completed',
        ]);

        $shipment = CsaShipment::create([
            'csa_import_id' => $import->id,
            'no_sj' => 'SJ-TEST-001',
            'target_sheet' => 'CIREBON',
            'kode_gudang' => 'GMCRB',
            'total_nominal_sj' => 5000000.00,
            'qty_unit' => 5,
        ]);

        Http::fake([
            'https://script.google.com/*' => Http::response([
                'status' => 'success',
                'sheet' => 'CIREBON',
                'inserted' => 1,
                'skipped' => 0,
            ], 200),
        ]);

        $service = new GoogleSheetWebhookService('https://script.google.com/macros/s/test/exec');
        $result = $service->syncShipments(collect([$shipment]));

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['inserted']);
        $this->assertDatabaseHas('csa_shipments', [
            'id' => $shipment->id,
            'is_synced' => true,
        ]);
    }

    public function test_ekspedisi_is_normalized_to_google_sheet_dropdown_values(): void
    {
        [$jnt, $shopee] = $this->createShipments('JAKARTA PIK', 2)->all();
        $jnt->update(['nama_ekspedisi' => 'J&T Express']);
        $shopee->update(['nama_ekspedisi' => 'Shopee Xpress']);

        $service = new GoogleSheetWebhookService;

        $this->assertSame('J&T', $service->transformShipmentToRow($jnt)[15]);
        $this->assertSame('Shopee Xpress', $service->transformShipmentToRow($shopee)[15]);
        $this->assertDatabaseHas('csa_shipments', ['id' => $jnt->id, 'nama_ekspedisi' => 'J&T Express']);
    }

    public function test_google_sheet_sync_flags_rows_already_in_sheet_without_overwriting(): void
    {
        $shipments = $this->createShipments('BANDUNG', 3);

        Http::fake([
            'https://script.google.com/*' => Http::response([
                'status' => 'success',
                'inserted' => 2,
                'skipped_duplicate' => 1,
                'skipped_indexes' => [1],
            ], 200),
        ]);

        $result = (new GoogleSheetWebhookService('https://script.google.com/macros/s/test/exec'))
            ->syncShipments($shipments);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['inserted']);
        $this->assertSame(1, $result['skipped']);

        $this->assertDatabaseHas('csa_shipments', ['id' => $shipments[0]->id, 'is_synced' => true, 'already_in_sheet' => false]);
        $this->assertDatabaseHas('csa_shipments', ['id' => $shipments[1]->id, 'is_synced' => true, 'already_in_sheet' => true]);
        $this->assertDatabaseHas('csa_shipments', ['id' => $shipments[2]->id, 'is_synced' => true, 'already_in_sheet' => false]);
    }

    public function test_google_sheet_sync_resets_highlight_only_until_first_chunk_that_inserts(): void
    {
        $shipments = $this->createShipments('BANDUNG', 250);
        $requestCount = 0;

        Http::fake(function () use (&$requestCount) {
            $requestCount++;

            // Batch pertama semua sudah ada di sheet, batch berikutnya tertulis
            return $requestCount === 1
                ? Http::response(['status' => 'success', 'inserted' => 0, 'skipped_indexes' => range(0, 99)], 200)
                : Http::response(['status' => 'success', 'inserted' => 100, 'skipped_indexes' => []], 200);
        });

        (new GoogleSheetWebhookService('https://script.google.com/macros/s/test/exec'))
            ->syncShipments($shipments);

        $resetFlags = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['resetHighlight'])->all();

        $this->assertSame([true, true, false], $resetFlags);
    }

    public function test_google_sheet_sync_can_resend_without_resetting_highlight(): void
    {
        $shipments = $this->createShipments('BANDUNG', 150);

        Http::fake(fn () => Http::response(['status' => 'success', 'inserted' => 100, 'skipped_indexes' => []], 200));

        (new GoogleSheetWebhookService('https://script.google.com/macros/s/test/exec'))
            ->syncShipments($shipments, resetHighlight: false);

        $resetFlags = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['resetHighlight'])->all();

        $this->assertSame([false, false], $resetFlags);
    }

    public function test_google_sheet_sync_does_not_mark_synced_when_apps_script_returns_error(): void
    {
        $shipments = $this->createShipments('BANDUNG', 1);

        Http::fake([
            'https://script.google.com/*' => Http::response([
                'status' => 'error',
                'message' => 'Server sheet sedang sibuk, silakan coba beberapa saat lagi.',
            ], 200),
        ]);

        $result = (new GoogleSheetWebhookService('https://script.google.com/macros/s/test/exec'))
            ->syncShipments($shipments);

        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('csa_shipments', ['id' => $shipments[0]->id, 'is_synced' => false]);
        $this->assertStringContainsString('sedang sibuk', $shipments[0]->fresh()->sync_error);
    }

    /**
     * @return Collection<int, CsaShipment>
     */
    private function createShipments(string $targetSheet, int $count): Collection
    {
        $import = CsaImport::create([
            'user_id' => User::factory()->create()->id,
            'file_name' => 'test.xlsx',
            'file_path' => '/tmp/test.xlsx',
            'status' => 'completed',
        ]);

        return collect(range(1, $count))->map(fn (int $i) => CsaShipment::create([
            'csa_import_id' => $import->id,
            'no_sj' => 'SJ-'.$targetSheet.'-'.$i,
            'target_sheet' => $targetSheet,
            'kode_gudang' => 'GMBDG',
            'total_nominal_sj' => 1000000,
            'qty_unit' => 1,
        ]));
    }
}

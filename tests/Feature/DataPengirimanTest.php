<?php

namespace Tests\Feature;

use App\Filament\Resources\CsaShipments\Pages\ListCsaShipments;
use App\Filament\Resources\CsaShipments\Tables\DataPengirimanTable;
use App\Jobs\ImportShipmentSpreadsheetJob;
use App\Jobs\ProcessCsaImportJob;
use App\Models\CsaImport;
use App\Models\CsaShipment;
use App\Models\Expedition;
use App\Models\User;
use App\Services\CsaExcelParserService;
use App\Services\DataPengirimanExporter;
use App\Services\ShipmentSpreadsheetImporter;
use App\Support\ShipmentOptions;
use BezhanSalleh\FilamentShield\Support\Utils;
use DateTimeImmutable;
use Exception;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DataPengirimanTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'ViewAny:CsaShipment', 'View:CsaShipment', 'Create:CsaShipment', 'Update:CsaShipment',
        'Delete:CsaShipment', 'DeleteAny:CsaShipment',
    ];

    public function test_reupload_updates_csa_data_without_overwriting_admin_fields(): void
    {
        $oldImport = $this->import();
        $shipment = $this->shipment('SJ-001', 'CIREBON', [
            'csa_import_id' => $oldImport->id,
            'tujuan_dealer' => 'TOKO LAMA',
            'total_nominal_sj' => 100000,
            'nama_ekspedisi' => 'RAX',
            'no_resi_awb' => 'RESI-ADMIN',
            'tanggal_diterima' => '2026-09-05',
            'status_pengiriman' => 'SELESAI',
        ]);

        $newImport = $this->import();
        $this->fakeParser([
            $this->parsedItem('SJ-001', ['tujuan_dealer' => 'TOKO BARU', 'total_nominal_sj' => 250000, 'nama_ekspedisi' => 'J&T Express', 'no_resi_awb' => 'RESI-CSA']),
            $this->parsedItem('SJ-002'),
        ]);

        (new ProcessCsaImportJob($newImport))->handle(app(CsaExcelParserService::class));

        $this->assertSame(1, CsaShipment::where('no_sj', 'SJ-001')->count());
        $shipment->refresh();
        $this->assertSame('TOKO BARU', $shipment->tujuan_dealer);
        $this->assertEquals(250000, (float) $shipment->total_nominal_sj);
        $this->assertSame($newImport->id, $shipment->csa_import_id);
        // Isian admin tetap
        $this->assertSame('RAX', $shipment->nama_ekspedisi);
        $this->assertSame('RESI-ADMIN', $shipment->no_resi_awb);
        $this->assertSame('SELESAI', $shipment->status_pengiriman);
        $this->assertSame('2026-09-05', $shipment->tanggal_diterima->toDateString());

        $this->assertDatabaseHas('csa_shipments', ['no_sj' => 'SJ-002', 'source' => CsaShipment::SOURCE_CSA, 'csa_import_id' => $newImport->id]);
        $newImport->refresh();
        $this->assertSame(1, $newImport->total_new);
        $this->assertSame(1, $newImport->total_updated);
    }

    public function test_reupload_fills_admin_fields_that_are_still_empty(): void
    {
        $shipment = $this->shipment('SJ-001', 'CIREBON', ['nama_ekspedisi' => null, 'no_resi_awb' => null]);

        $import = $this->import();
        $this->fakeParser([$this->parsedItem('SJ-001', ['nama_ekspedisi' => 'J&T Express', 'no_resi_awb' => 'RESI-CSA'])]);
        (new ProcessCsaImportJob($import))->handle(app(CsaExcelParserService::class));

        $shipment->refresh();
        $this->assertSame('J&T Express', $shipment->nama_ekspedisi);
        $this->assertSame('RESI-CSA', $shipment->no_resi_awb);
    }

    public function test_upload_skips_sj_already_imported_from_the_spreadsheet_with_the_same_total(): void
    {
        $spreadsheet = ['source' => CsaShipment::SOURCE_SPREADSHEET, 'total_nominal_sj' => 1000000, 'nama_ekspedisi' => 'RAX'];
        $sama = $this->shipment('SJ-SAMA', 'CIREBON', $spreadsheet);
        // Admin cabang mengetik nominal termasuk PPN 11%, atau mengosongkan total
        $this->shipment('SJ-PPN', 'CIREBON', [...$spreadsheet, 'total_nominal_sj' => 5420000]);
        $this->shipment('SJ-TOTAL-KOSONG', 'CIREBON', [...$spreadsheet, 'total_nominal_sj' => 0]);
        $this->shipment('SJ-TOTAL-BEDA', 'CIREBON', $spreadsheet);
        $this->shipment('SJ-CABANG-LAIN', 'BANDUNG', $spreadsheet);

        $import = $this->import();
        $this->fakeParser([
            $this->parsedItem('SJ-SAMA', ['total_nominal_sj' => 1000000.4, 'nama_ekspedisi' => 'J&T Express']),
            $this->parsedItem('SJ-PPN', ['total_nominal_sj' => 4882882.88]),
            $this->parsedItem('SJ-TOTAL-KOSONG'),
            $this->parsedItem('SJ-TOTAL-BEDA', ['total_nominal_sj' => 1500000]),
            $this->parsedItem('SJ-CABANG-LAIN'),
            $this->parsedItem('SJ-BARU'),
        ]);
        (new ProcessCsaImportJob($import))->handle(app(CsaExcelParserService::class));

        // Identik (No SJ + total di cabang yang sama): dilewati dan data spreadsheet tidak ditimpa
        $this->assertSame(1, CsaShipment::where('no_sj', 'SJ-SAMA')->count());
        $this->assertSame('RAX', $sama->fresh()->nama_ekspedisi);
        $this->assertSame(CsaShipment::SOURCE_SPREADSHEET, $sama->fresh()->source);
        $this->assertSame(1, CsaShipment::where('no_sj', 'SJ-PPN')->count());
        $this->assertSame(1, CsaShipment::where('no_sj', 'SJ-TOTAL-KOSONG')->count());

        // Total beda atau cabang lain: tetap masuk sebagai baris baru dengan No SJ yang sama
        $this->assertSame(2, CsaShipment::where('no_sj', 'SJ-TOTAL-BEDA')->count());
        $this->assertSame(2, CsaShipment::where('no_sj', 'SJ-CABANG-LAIN')->count());
        $this->assertSame(1, CsaShipment::where('no_sj', 'SJ-BARU')->count());

        $import->refresh();
        $this->assertSame(3, $import->total_new);
        $this->assertSame(0, $import->total_updated);
        $this->assertSame(3, $import->total_skipped);
    }

    public function test_rows_added_by_the_latest_upload_are_marked_as_newest(): void
    {
        $this->travelTo(now()->subDays(10));
        $oldImport = $this->import();
        $old = $this->shipment('SJ-LAMA', 'CIREBON', ['csa_import_id' => $oldImport->id]);
        $this->travelBack();

        $newImport = $this->import();
        $this->travel(1)->minutes();
        $this->fakeParser([$this->parsedItem('SJ-LAMA'), $this->parsedItem('SJ-BARU')]);
        (new ProcessCsaImportJob($newImport))->handle(app(CsaExcelParserService::class));

        $baru = CsaShipment::where('no_sj', 'SJ-BARU')->firstOrFail();
        $old->refresh();

        // SJ lama ikut diperbarui oleh upload baru, tapi bukan data baru
        $this->assertSame($newImport->id, $old->csa_import_id);
        $this->assertFalse(DataPengirimanTable::isNewest($old));
        $this->assertTrue(DataPengirimanTable::isNewest($baru));

        $this->actingAs($this->centralAdmin());

        Livewire::test(ListCsaShipments::class)
            ->filterTable('terbaru', true)
            ->assertCanSeeTableRecords([$baru])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_first_upload_after_a_spreadsheet_import_is_marked_as_newest(): void
    {
        $this->travelTo(now()->subDay());
        $imported = $this->shipment('SJ-SHEET', 'CIREBON', ['source' => CsaShipment::SOURCE_SPREADSHEET, 'total_nominal_sj' => 1000000]);
        $this->travelBack();

        $import = $this->import();
        $this->travel(1)->minutes();
        $this->fakeParser([$this->parsedItem('SJ-SHEET'), $this->parsedItem('SJ-BARU')]);
        (new ProcessCsaImportJob($import))->handle(app(CsaExcelParserService::class));

        // Data hasil import spreadsheet jadi pembanding, jadi SJ dari upload CSA berikutnya langsung merah
        $this->assertTrue(DataPengirimanTable::isNewest(CsaShipment::where('no_sj', 'SJ-BARU')->firstOrFail()));
        $this->assertFalse(DataPengirimanTable::isNewest($imported->fresh()));
        $this->assertSame(1, CsaShipment::where('no_sj', 'SJ-SHEET')->count());
    }

    public function test_first_upload_into_an_empty_table_is_not_marked_as_newest(): void
    {
        $import = $this->import();
        $this->travel(1)->minutes();
        $this->fakeParser([$this->parsedItem('SJ-001'), $this->parsedItem('SJ-002')]);
        (new ProcessCsaImportJob($import))->handle(app(CsaExcelParserService::class));

        // Belum ada data lama sebagai pembanding: semua dianggap data awal, tidak ada yang merah
        foreach (CsaShipment::all() as $shipment) {
            $this->assertFalse(DataPengirimanTable::isNewest($shipment));
        }
    }

    public function test_deleting_an_upload_keeps_its_shipments(): void
    {
        $import = $this->import();
        $shipment = $this->shipment('SJ-001', 'CIREBON', ['csa_import_id' => $import->id]);

        $import->delete();

        $this->assertDatabaseHas('csa_shipments', ['id' => $shipment->id, 'csa_import_id' => null]);
    }

    public function test_lead_times_are_calculated_from_the_dates(): void
    {
        $shipment = $this->shipment('SJ-001', 'CIREBON', [
            'tanggal_order' => '2026-09-01',
            'tanggal_kirim' => '2026-09-02',
            'tanggal_diterima' => '2026-09-05',
        ]);

        $this->assertSame(1, $shipment->lead_time_proses);
        $this->assertSame(3, $shipment->lead_time_kirim);
        $this->assertSame(4, $shipment->lead_time_keseluruhan);

        $shipment->update(['tanggal_diterima' => null]);
        $this->assertNull($shipment->fresh()->lead_time_kirim);
    }

    public function test_dropdown_options_follow_each_spreadsheet_tab(): void
    {
        // SURABAYA punya dropdown ekspedisi sendiri di spreadsheet; judul kolom yang ikut masuk daftar dibuang
        $surabaya = ShipmentOptions::ekspedisi('SURABAYA');
        $this->assertArrayHasKey('DELIVEREE+RIZKI', $surabaya);
        $this->assertArrayHasKey('GINTA CARGO', $surabaya);
        $this->assertArrayNotHasKey('NAMA EKSPEDISI', $surabaya);
        $this->assertArrayNotHasKey('PO PATABA', $surabaya);

        // PURWOKERTO punya dropdown status pembayaran & ketentuan biaya sendiri
        $this->assertArrayHasKey('BAYAR DI TOKO', ShipmentOptions::statusPembayaran('PURWOKERTO'));
        $this->assertArrayHasKey('KLAIM BENSIN', ShipmentOptions::ketentuanBiayaKirim('PURWOKERTO'));

        // MEDAN tidak punya dropdown ekspedisi: pakai gabungan + master Ekspedisi + yang sudah dipakai cabang
        Expedition::create(['name' => 'LIONEL EXPRESS', 'code' => 'LIONEL', 'is_active' => true]);
        $this->shipment('SJ-MDN', 'MEDAN', ['nama_ekspedisi' => 'EKSPEDISI LOKAL MEDAN']);
        $medan = ShipmentOptions::ekspedisi('MEDAN');
        $this->assertArrayHasKey('LIONEL EXPRESS', $medan);
        $this->assertArrayHasKey('EKSPEDISI LOKAL MEDAN', $medan);
        $this->assertArrayHasKey('21 EXPRESS', $medan);

        // Nilai lama di luar daftar tetap muncul agar tidak hilang saat diedit
        $this->assertArrayHasKey('JNT', ShipmentOptions::ekspedisi('SURABAYA', 'JNT'));
    }

    public function test_branch_admin_only_sees_own_branch_without_cabang_dropdown(): void
    {
        $bandung = $this->shipment('SJ-BDG', 'BANDUNG');
        $cirebon = $this->shipment('SJ-CRB', 'CIREBON');

        $this->actingAs($this->branchAdmin('BANDUNG'));

        Livewire::test(ListCsaShipments::class)
            ->assertCanSeeTableRecords([$bandung])
            ->assertCanNotSeeTableRecords([$cirebon])
            ->assertTableFilterHidden('target_sheet');
    }

    public function test_central_admin_picks_a_branch_from_the_cabang_dropdown(): void
    {
        $bandung = $this->shipment('SJ-BDG', 'BANDUNG');
        $cirebon = $this->shipment('SJ-CRB', 'CIREBON');

        $this->actingAs($this->centralAdmin());

        $page = Livewire::test(ListCsaShipments::class)
            ->assertTableFilterVisible('target_sheet')
            ->assertCanSeeTableRecords([$bandung, $cirebon]);

        // Urutan pilihan sama dengan tab spreadsheet (cabang kosong tetap ada), lengkap dengan jumlah SJ
        $options = $page->instance()->getTable()->getFilter('target_sheet')->getOptions();
        $this->assertSame(['CIREBON', 'JAKARTA PIK', 'RETUR', 'ACC CIREBON TP', 'JAKARTA PC', 'BANDUNG'], array_slice(array_keys($options), 0, 6));
        $this->assertSame('CIREBON (1)', $options['CIREBON']);
        $this->assertSame('RETUR (0)', $options['RETUR']);

        $page->filterTable('target_sheet', 'CIREBON')
            ->assertCanSeeTableRecords([$cirebon])
            ->assertCanNotSeeTableRecords([$bandung]);
    }

    public function test_bulk_fill_only_changes_the_filled_fields(): void
    {
        $first = $this->shipment('SJ-001', 'CIREBON', ['nama_ekspedisi' => 'RAX']);
        $second = $this->shipment('SJ-002', 'CIREBON', ['nama_ekspedisi' => 'J&T']);

        $this->actingAs($this->centralAdmin());

        Livewire::test(ListCsaShipments::class)
            ->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make('isiDataPengiriman')->table()->bulk(), data: [
                'status_pengiriman' => 'ON DELIVERY',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('ON DELIVERY', $first->fresh()->status_pengiriman);
        $this->assertSame('ON DELIVERY', $second->fresh()->status_pengiriman);
        $this->assertSame('RAX', $first->fresh()->nama_ekspedisi);
        $this->assertSame('J&T', $second->fresh()->nama_ekspedisi);
    }

    public function test_admin_fields_can_be_filled_directly_in_the_table(): void
    {
        $shipment = $this->shipment('SJ-001', 'CIREBON');
        Expedition::create(['name' => 'RAX', 'code' => 'RAX', 'is_active' => true]);

        $this->actingAs($this->centralAdmin());

        Livewire::test(ListCsaShipments::class)
            ->call('updateTableColumnState', 'tanggal_diterima', (string) $shipment->getKey(), '2026-09-05')
            ->call('updateTableColumnState', 'qty_koli', (string) $shipment->getKey(), '3')
            ->call('updateTableColumnState', 'nama_ekspedisi', (string) $shipment->getKey(), 'RAX')
            ->call('updateTableColumnState', 'status_pengiriman', (string) $shipment->getKey(), 'SELESAI');

        $shipment->refresh();
        $this->assertSame('2026-09-05', $shipment->tanggal_diterima->toDateString());
        $this->assertSame('SELESAI', $shipment->status_pengiriman);
        $this->assertSame(3, $shipment->qty_koli);
        $this->assertSame('RAX', $shipment->nama_ekspedisi);
    }

    public function test_editing_a_csa_row_keeps_csa_data_locked(): void
    {
        $shipment = $this->shipment('SJ-001', 'CIREBON', ['tujuan_dealer' => 'TOKO ASLI']);
        Expedition::create(['name' => 'RAX', 'code' => 'RAX', 'is_active' => true]);

        $this->actingAs($this->centralAdmin());

        Livewire::test(ListCsaShipments::class)
            ->callAction(TestAction::make('edit')->table($shipment), data: [
                'tujuan_dealer' => 'DIUBAH',
                'nama_ekspedisi' => 'RAX',
                'no_resi_awb' => 'RESI-123',
            ])
            ->assertHasNoFormErrors();

        $shipment->refresh();
        $this->assertSame('TOKO ASLI', $shipment->tujuan_dealer);
        $this->assertSame('RAX', $shipment->nama_ekspedisi);
        $this->assertSame('RESI-123', $shipment->no_resi_awb);
    }

    public function test_branch_admin_adds_a_manual_row_for_own_branch(): void
    {
        $this->shipment('SJ-BDG', 'BANDUNG');
        $this->actingAs($this->branchAdmin('BANDUNG'));

        Livewire::test(ListCsaShipments::class)
            ->callAction('create', data: [
                'no_sj' => 'RET-001',
                'tanggal_kirim' => '2026-10-01',
                'tujuan_dealer' => 'CV. TOP SEMARANG',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('csa_shipments', [
            'no_sj' => 'RET-001',
            'target_sheet' => 'BANDUNG',
            'source' => CsaShipment::SOURCE_MANUAL,
            'csa_import_id' => null,
        ]);

        // Cabang lain tidak bisa dipilih meski nilainya dipaksa dari browser
        Livewire::test(ListCsaShipments::class)
            ->callAction('create', data: [
                'no_sj' => 'RET-002',
                'tanggal_kirim' => '2026-10-01',
                'tujuan_dealer' => 'CV. TOP SEMARANG',
                'target_sheet' => 'CIREBON',
            ]);

        $this->assertDatabaseMissing('csa_shipments', ['target_sheet' => 'CIREBON']);
    }

    public function test_export_writes_the_spreadsheet_columns(): void
    {
        $this->shipment('SJ-001', 'CIREBON', [
            'tanggal_order' => '2026-09-01',
            'tanggal_kirim' => '2026-09-02',
            'tanggal_diterima' => '2026-09-04',
            'nama_ekspedisi' => 'RAX',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        app(DataPengirimanExporter::class)->write(CsaShipment::query()->orderBy('id'), $path);

        $rows = [];
        $reader = new Reader;
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        $this->assertSame(DataPengirimanExporter::HEADERS, $rows[0]);
        $this->assertSame('SJ-001', $rows[1][8]);
        $this->assertSame('RAX', $rows[1][15]);
        $this->assertEquals(3, $rows[1][23]); // lead time keseluruhan
    }

    public function test_export_action_downloads_an_excel_file(): void
    {
        $this->shipment('SJ-001', 'CIREBON');
        $this->actingAs($this->centralAdmin());

        Livewire::test(ListCsaShipments::class)
            ->callAction('export')
            ->assertFileDownloaded();
    }

    public function test_spreadsheet_import_copies_each_tab_into_its_branch(): void
    {
        $path = $this->spreadsheet([
            'CIREBON' => [
                DataPengirimanExporter::HEADERS,
                $this->sheetRow([
                    'A' => new DateTimeImmutable('2026-01-02'), 'B' => new DateTimeImmutable('2026-01-02'),
                    'C' => 'CV. TOP SELULAR', 'D' => 'CIREBON', 'E' => 'LOTUS CELL', 'F' => 'JL RAYA ANJATAN',
                    'G' => 'INDRAMAYU', 'H' => 'REALME', 'I' => 2601000271, 'J' => 15880000, 'K' => 'REALME - DP CIREBON',
                    'L' => '3', 'M' => 1, 'N' => '3,20 KG', 'O' => 'INVOICE', 'P' => 'J&T', 'Q' => 1349678322, 'R' => 9000,
                    'S' => 'TAGIHAN BULANAN', 'T' => 'DITERIMA', 'U' => new DateTimeImmutable('2026-01-05'), 'Y' => '3x REALME',
                ]),
                // Tanggal diketik sebagai teks format Amerika; jenis barang ditulis di QTY UNIT
                $this->sheetRow(['B' => '05/30/2026', 'E' => 'GUDANG TOP', 'I' => 'MANUAL', 'L' => 'DOKUMEN', 'U' => 'BELUM TAU']),
                // Baris template yang hanya berisi pilihan bawaan
                $this->sheetRow(['D' => 'CIREBON', 'S' => 'TAGIHAN BULANAN']),
            ],
            'JAKARTA PC ' => [
                DataPengirimanExporter::HEADERS,
                $this->sheetRow(['B' => '04 DECEMBER 2025', 'E' => 'SHOPEE/RAHMAT', 'R' => 24326]),
                $this->sheetRow(['B' => new DateTimeImmutable('2026-03-27'), 'E' => 'SHOPEE/ANI']),
                // Tanpa tahun: ikut tahun baris sebelumnya
                $this->sheetRow(['B' => '30 MARC', 'E' => 'SHOPEE/BUDI']),
            ],
            'CATATAN' => [['Rekap bulanan, bukan tabel pengiriman']],
        ]);

        $result = app(ShipmentSpreadsheetImporter::class)->import($path);
        @unlink($path);

        $this->assertSame([
            'CIREBON' => ['imported' => 2, 'replaced' => 0],
            'JAKARTA PC' => ['imported' => 3, 'replaced' => 0],
        ], $result['tabs']);
        $this->assertSame(['CATATAN'], $result['skipped']);
        $this->assertSame(1, $result['unreadable']); // "BELUM TAU" di TANGGAL DITERIMA

        $shipment = CsaShipment::where('no_sj', '2601000271')->firstOrFail();
        $this->assertSame(CsaShipment::SOURCE_SPREADSHEET, $shipment->source);
        $this->assertSame('CIREBON', $shipment->target_sheet);
        $this->assertSame('2026-01-02', $shipment->tanggal_order->toDateString());
        $this->assertSame('2026-01-05', $shipment->tanggal_diterima->toDateString());
        $this->assertSame('LOTUS CELL', $shipment->tujuan_dealer);
        $this->assertEquals(15880000, (float) $shipment->total_nominal_sj);
        $this->assertSame(3, $shipment->qty_unit);
        $this->assertEquals(3.2, (float) $shipment->berat);
        $this->assertSame('J&T', $shipment->nama_ekspedisi);
        $this->assertSame('1349678322', $shipment->no_resi_awb);
        $this->assertEquals(9000, (float) $shipment->biaya_kirim);
        $this->assertSame('3x REALME', $shipment->ket_isi_unit);
        $this->assertTrue($shipment->is_synced);

        $dokumen = CsaShipment::where('no_sj', 'MANUAL')->firstOrFail();
        $this->assertSame('2026-05-30', $dokumen->tanggal_kirim->toDateString());
        $this->assertSame('DOKUMEN', $dokumen->ket_isi_unit);
        $this->assertSame(0, $dokumen->qty_unit);
        $this->assertNull($dokumen->tanggal_diterima);

        // Nama tab "JAKARTA PC " (ada spasi) disamakan dengan cabang di menu
        $this->assertSame(
            ['2025-12-04', '2026-03-27', '2026-03-30'],
            CsaShipment::where('target_sheet', 'JAKARTA PC')->orderBy('id')->get()
                ->map(fn (CsaShipment $row): string => $row->tanggal_kirim->toDateString())->all(),
        );
        $this->assertSame(5, CsaShipment::count());
    }

    public function test_reimporting_replaces_only_earlier_spreadsheet_rows_of_the_same_branch(): void
    {
        $csa = $this->shipment('SJ-CSA', 'CIREBON');
        $manual = $this->shipment('RET-001', 'CIREBON', ['source' => CsaShipment::SOURCE_MANUAL]);
        $earlier = $this->shipment('SJ-LAMA', 'CIREBON', ['source' => CsaShipment::SOURCE_SPREADSHEET]);
        $otherBranch = $this->shipment('SJ-BDG', 'BANDUNG', ['source' => CsaShipment::SOURCE_SPREADSHEET]);

        $path = $this->spreadsheet(['CIREBON' => [DataPengirimanExporter::HEADERS, $this->sheetRow(['E' => 'TOKO BARU', 'I' => 'SJ-BARU'])]]);

        $result = app(ShipmentSpreadsheetImporter::class)->import($path);

        $this->assertSame(['imported' => 1, 'replaced' => 1], $result['tabs']['CIREBON']);
        $this->assertModelMissing($earlier);
        $this->assertModelExists($csa);
        $this->assertModelExists($manual);
        $this->assertModelExists($otherBranch);

        // Tanpa opsi ganti, import ulang menambah baris
        app(ShipmentSpreadsheetImporter::class)->import($path, replaceExisting: false);
        @unlink($path);

        $this->assertSame(2, CsaShipment::where('no_sj', 'SJ-BARU')->count());
    }

    public function test_import_spreadsheet_action_runs_in_the_background_for_central_admins(): void
    {
        Queue::fake();
        Storage::fake('local');
        $admin = $this->centralAdmin();
        $this->actingAs($admin);

        Livewire::test(ListCsaShipments::class)
            ->callAction('importSpreadsheet', data: [
                'file' => UploadedFile::fake()->create('Update Pengiriman 2026.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Import spreadsheet sedang diproses');

        Queue::assertPushed(ImportShipmentSpreadsheetJob::class, fn (ImportShipmentSpreadsheetJob $job): bool => $job->fileName === 'Update Pengiriman 2026.xlsx'
            && $job->replaceExisting
            && $job->userId === $admin->id
            && Storage::disk('local')->exists($job->path));

        // File berisi semua cabang, jadi admin cabang tidak bisa mengimpor
        $this->actingAs($this->branchAdmin('BANDUNG'));
        Livewire::test(ListCsaShipments::class)->assertActionHidden('importSpreadsheet');
    }

    public function test_import_job_notifies_the_uploader(): void
    {
        Storage::fake('local');
        $admin = $this->centralAdmin();
        $source = $this->spreadsheet(['BANDUNG' => [
            DataPengirimanExporter::HEADERS,
            $this->sheetRow(['E' => 'TOKO A', 'I' => 'SJ-1']),
            $this->sheetRow(['E' => 'TOKO B', 'I' => 'SJ-2']),
        ]]);
        Storage::disk('local')->put('pengiriman-imports/update.xlsx', (string) file_get_contents($source));
        @unlink($source);

        $job = new ImportShipmentSpreadsheetJob('pengiriman-imports/update.xlsx', 'Update Pengiriman.xlsx', true, $admin->id);
        $job->handle(app(ShipmentSpreadsheetImporter::class));

        $this->assertSame(2, CsaShipment::where('target_sheet', 'BANDUNG')->count());
        Storage::disk('local')->assertMissing('pengiriman-imports/update.xlsx');

        $notification = $admin->notifications()->sole();
        $this->assertSame('Import spreadsheet selesai: 2 baris', $notification->data['title']);
        $this->assertStringContainsString('BANDUNG 2', $notification->data['body']);

        $job->failed(new Exception('File rusak'));
        $this->assertContains('Import spreadsheet gagal', $admin->notifications()->get()->pluck('data.title')->all());
    }

    private function import(): CsaImport
    {
        return CsaImport::create([
            'user_id' => User::factory()->create()->id,
            'file_name' => 'laporan.xlsx',
            'file_path' => '/tmp/laporan.xlsx',
            'status' => 'completed',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shipment(string $noSj, string $cabang, array $attributes = []): CsaShipment
    {
        return CsaShipment::create(array_merge([
            'source' => CsaShipment::SOURCE_CSA,
            'no_sj' => $noSj,
            'badan_usaha' => 'PT Media Selular Indonesia',
            'kode_gudang' => 'G-'.$cabang,
            'target_sheet' => $cabang,
            'tujuan_dealer' => 'TOKO '.$noSj,
            'tanggal_kirim' => '2026-09-02',
            'total_nominal_sj' => 1000000,
            'qty_unit' => 1,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function parsedItem(string $noSj, array $overrides = []): array
    {
        return array_merge([
            'no_sj' => $noSj, 'no_trans' => null, 'no_so' => null,
            'tanggal_order' => '2026-09-01', 'tanggal_kirim' => '2026-09-02',
            'badan_usaha' => 'PT Media Selular Indonesia', 'kode_gudang' => 'GMCRB', 'nama_gudang' => 'CIREBON',
            'target_sheet' => 'CIREBON', 'tujuan_dealer' => 'TOKO '.$noSj, 'alamat_kirim' => null, 'nama_kota' => 'CIREBON',
            'brand' => 'ITEL', 'reff_note' => null, 'total_nominal_sj' => 1000000, 'qty_unit' => 1, 'qty_koli' => 0,
            'berat' => 0, 'ketentuan_biaya_kirim' => null, 'nama_ekspedisi' => null, 'no_resi_awb' => null,
            'biaya_kirim' => null, 'status_pembayaran' => null, 'status_pengiriman' => null, 'ket_isi_unit' => '1x ITEL',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function fakeParser(array $items): void
    {
        $this->mock(CsaExcelParserService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('parseAndAggregate')
            ->andReturn([
                'total_raw_rows' => count($items),
                'total_shipments' => count($items),
                'by_sheet' => [],
                'shipments' => $items,
            ]));
    }

    /**
     * File .xlsx dengan satu tab per cabang; baris pertama tiap tab biasanya header spreadsheet.
     *
     * @param  array<string, list<array<int, mixed>>>  $tabs
     */
    private function spreadsheet(array $tabs): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('pengiriman_').'.xlsx';
        $dateStyle = (new Style)->setFormat('dd/mm/yyyy');

        $writer = new Writer;
        $writer->openToFile($path);

        foreach (array_keys($tabs) as $index => $name) {
            if ($index > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $writer->getCurrentSheet()->setName($name);

            foreach ($tabs[$name] as $values) {
                $writer->addRow(Row::fromValuesWithStyles($values, null, [0 => $dateStyle, 1 => $dateStyle, 20 => $dateStyle]));
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * Satu baris spreadsheet (kolom A–Y) dari nilai per huruf kolom.
     *
     * @param  array<string, mixed>  $cells
     * @return list<mixed>
     */
    private function sheetRow(array $cells): array
    {
        $row = array_fill(0, 25, null);

        foreach ($cells as $column => $value) {
            $row[ord($column) - ord('A')] = $value;
        }

        return $row;
    }

    private function centralAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        $role->givePermissionTo($this->permissions());

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function branchAdmin(string $cabang): User
    {
        $role = Role::firstOrCreate(['name' => 'admin_cabang', 'guard_name' => 'web']);
        $role->givePermissionTo($this->permissions());

        $user = User::factory()->create(['cabang' => $cabang]);
        $user->assignRole(Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']), $role);

        return $user;
    }

    /**
     * @return list<string>
     */
    private function permissions(): array
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        return self::PERMISSIONS;
    }
}

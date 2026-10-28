<?php

namespace Tests\Feature;

use App\Filament\Resources\CsaImports\Pages\ViewCsaImport;
use App\Filament\Resources\CsaImports\RelationManagers\ItemsRelationManager;
use App\Jobs\ProcessCsaImportJob;
use App\Models\CsaImport;
use App\Models\CsaImportItem;
use App\Models\User;
use App\Services\CsaExcelParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class CsaSalesItemsTest extends TestCase
{
    use RefreshDatabase;

    private const CAMON_GREEN = 'TECNO - HP 4G - CAMON 50 - CN5 8/128 - N - NEBULA TITANIUM';

    public function test_sales_report_is_summarised_per_warehouse_per_item_and_colour(): void
    {
        $import = $this->runImport($this->salesReport([
            // Laporan berisi satu baris per unit/IMEI; retur (qty minus) mengurangi
            ['SJ-1', 'GMSMG', 'GUDANG MSIS SEMARANG', 'FG2-01', self::CAMON_GREEN, 'ANDROID', 'TECNO', 1, 3000000],
            ['SJ-1', 'GMSMG', 'GUDANG MSIS SEMARANG', 'FG2-01', self::CAMON_GREEN, 'ANDROID', 'TECNO', 1, 3000000],
            ['SJ-2', 'GMSMG', 'GUDANG MSIS SEMARANG', 'FG2-01', self::CAMON_GREEN, 'ANDROID', 'TECNO', 1, 3000000],
            ['SJ-2', 'GMSMG', 'GUDANG MSIS SEMARANG', 'FG2-01', self::CAMON_GREEN, 'ANDROID', 'TECNO', -1, -3000000],
            ['SJ-2', 'GMSMG', 'GUDANG MSIS SEMARANG', 'AC-01', 'TECNO - DATA CABLE - TCD-CC02 1M - N - WHITE', 'ACCESSORIES', 'ACC', 2, 50000],
            ['SJ-3', 'GMBDG', 'GUDANG MSIS BANDUNG', 'FG2-02', 'TECNO - HP 4G - CAMON 50 - CN5 8/128 - N - MOONSHADOW BLACK', 'ANDROID', 'TECNO', 1, 3000000],
            ['SJ-3', 'GMBDG', 'GUDANG MSIS BANDUNG', 'FG2-01', self::CAMON_GREEN, 'ANDROID', 'TECNO', 1, 3000000],
        ]));

        $this->assertSame('completed', $import->status, (string) $import->error_message);
        $this->assertSame(4, $import->items()->count());
        // Periode dari baris "Tanggal : 01/10/2026 sd 27/10/2026", dipakai Alokasi Stok untuk laku per hari
        $this->assertSame('2026-10-01', $import->period_start->format('Y-m-d'));
        $this->assertSame(27, $import->periodDays());

        $semarangGreen = $import->items()->where('kode_gudang', 'GMSMG')->where('kode_barang', 'FG2-01')->sole();
        $this->assertSame('GUDANG MSIS SEMARANG', $semarangGreen->nama_gudang);
        $this->assertSame('TECNO - HP 4G - CAMON 50 - CN5 8/128 - N', $semarangGreen->model);
        $this->assertSame('NEBULA TITANIUM', $semarangGreen->warna);
        // Yang tampil di tabel: nama pendek tanpa brand/jenis, gudang tanpa "GUDANG MSIS"
        $this->assertSame('CAMON 50 CN5 8/128', $semarangGreen->shortModel());
        $this->assertSame('SEMARANG', $semarangGreen->warehouseLabel());
        $this->assertSame('TECNO', $semarangGreen->brand);
        $this->assertTrue($semarangGreen->is_unit);
        $this->assertSame(2, $semarangGreen->qty);
        $this->assertEquals(6000000, (float) $semarangGreen->nominal);

        // Warna lain = barang lain; gudang lain = baris lain
        $this->assertSame('MOONSHADOW BLACK', $import->items()->where('kode_barang', 'FG2-02')->sole()->warna);
        $this->assertSame(1, $import->items()->where('kode_gudang', 'GMBDG')->where('kode_barang', 'FG2-01')->sole()->qty);

        // Nama aksesoris tidak selalu diakhiri warna, jadi tidak dipisah
        $cable = $import->items()->where('kode_barang', 'AC-01')->sole();
        $this->assertFalse($cable->is_unit);
        $this->assertNull($cable->warna);
        $this->assertSame('TECNO - DATA CABLE - TCD-CC02 1M - N - WHITE', $cable->model);
    }

    public function test_processing_the_same_upload_again_replaces_its_item_summary(): void
    {
        $import = $this->runImport($this->salesReport([
            ['SJ-1', 'GMSMG', 'GUDANG MSIS SEMARANG', 'FG2-01', self::CAMON_GREEN, 'ANDROID', 'TECNO', 1, 3000000],
        ]));

        (new ProcessCsaImportJob($import))->handle(app(CsaExcelParserService::class));

        $this->assertSame(1, $import->items()->count());
        $this->assertSame(1, $import->items()->sole()->qty);
    }

    public function test_upload_page_shows_units_sold_per_item_with_accessories_behind_a_filter(): void
    {
        $import = $this->runImport($this->salesReport([
            ['SJ-1', 'GMSMG', 'GUDANG MSIS SEMARANG', 'FG2-01', self::CAMON_GREEN, 'ANDROID', 'TECNO', 1, 3000000],
            ['SJ-1', 'GMSMG', 'GUDANG MSIS SEMARANG', 'AC-01', 'TECNO - DATA CABLE - TCD-CC02 1M - N - WHITE', 'ACCESSORIES', 'ACC', 2, 50000],
            ['SJ-2', 'GMBDG', 'GUDANG MSIS BANDUNG', 'FG2-01', self::CAMON_GREEN, 'ANDROID', 'TECNO', 5, 15000000],
        ]));
        $unit = CsaImportItem::where('kode_gudang', 'GMSMG')->where('kode_barang', 'FG2-01')->sole();
        $cable = CsaImportItem::where('kode_barang', 'AC-01')->sole();

        $this->actingAs(User::factory()->create());

        $component = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $import, 'pageClass' => ViewCsaImport::class])
            ->assertCanSeeTableRecords([$unit])
            ->assertCanNotSeeTableRecords([$cable])
            ->assertSee(['SEMARANG', 'GMSMG', 'CAMON 50 CN5 8/128', 'NEBULA TITANIUM']);

        // Gudang yang paling banyak terjual tampil paling atas
        $this->assertSame(['GMBDG', 'GMSMG'], $component->instance()->getTableRecords()->pluck('kode_gudang')->all());

        $component->filterTable('is_unit', false)
            ->assertCanSeeTableRecords([$cable])
            ->assertCanNotSeeTableRecords([$unit]);
    }

    public function test_item_tab_is_hidden_for_transfer_order_uploads(): void
    {
        $transfer = CsaImport::create(['file_name' => 'to.xlsx', 'file_path' => '/tmp/to.xlsx', 'report_type' => CsaImport::REPORT_TRANSFER, 'status' => 'completed']);
        $sales = CsaImport::create(['file_name' => 'jual.xlsx', 'file_path' => '/tmp/jual.xlsx', 'report_type' => CsaImport::REPORT_SALES, 'status' => 'completed']);

        $this->assertFalse(ItemsRelationManager::canViewForRecord($transfer, ViewCsaImport::class));
        $this->assertTrue(ItemsRelationManager::canViewForRecord($sales, ViewCsaImport::class));
    }

    private function runImport(string $file): CsaImport
    {
        $import = CsaImport::create([
            'file_name' => 'laporan buku penjualan.xlsx',
            'file_path' => $file,
            'status' => 'pending',
            'total_raw_rows' => 0,
            'total_shipments' => 0,
            'total_synced' => 0,
        ]);

        (new ProcessCsaImportJob($import))->handle(app(CsaExcelParserService::class));

        return $import->refresh();
    }

    /**
     * File .xlsx berformat laporan [XRJUAL03] (header di baris 8) dengan kolom yang dibaca aplikasi.
     * Tiap baris: [No SJ, kode gudang, gudang, kode barang, nama barang, sort code, family, qty, jumlah].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: int, 8: int}>  $rows
     */
    private function salesReport(array $rows): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('buku_penjualan_').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Lap, Buku Penjualan');

        foreach (['[XRJUAL03] BAGUS3', 'LAPORAN BUKU PENJUALAN LENGKAP', 'PT Media Selular Indonesia', 'Jl. Tuparev Kertawinangun', 'Tanggal : 01/10/2026 sd 27/10/2026', 'Gudang  : (semua)', 'KodeItem: (semua)'] as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }

        $writer->addRow(Row::fromValues([
            'No', 'Tanggal', 'No Trans.', 'Nomor SO', 'No Faktur', 'Nomor SJ', 'Kode', 'Gudang', 'Customer', 'Nomor Resi',
            'Ref.Note', 'Kode Barang', 'Nama Barang', 'Sort Code', 'Family', 'Qty', 'Jumlah',
        ]));

        foreach ($rows as $index => [$sj, $kodeGudang, $gudang, $kodeBarang, $namaBarang, $sortCode, $family, $qty, $amount]) {
            $writer->addRow(Row::fromValues([
                $index + 1, 46311, 'TR-'.$sj, '', '', $sj, $kodeGudang, $gudang, 'TOKO '.$sj, '',
                'T-SEMARANG', $kodeBarang, $namaBarang, $sortCode, $family, $qty, $amount,
            ]));
        }

        $writer->close();

        return $path;
    }
}

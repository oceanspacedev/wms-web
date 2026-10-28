<?php

namespace Tests\Feature;

use App\Filament\Resources\StockAllocations\Pages\ViewStockAllocation;
use App\Filament\Resources\StockAllocations\RelationManagers\TransfersRelationManager;
use App\Models\CsaImport;
use App\Models\CsaImportItem;
use App\Models\StockAllocation;
use App\Models\StockAllocationTransfer;
use App\Models\User;
use App\Models\WarehouseMapping;
use App\Services\StockAllocationService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockReportAllocationTest extends TestCase
{
    use RefreshDatabase;

    private const CAMON = 'TECNO - HP 4G - CAMON 50 - CN5 8/128 - N - NEBULA TITANIUM';

    private const SPARK = 'TECNO - HP 4G - SPARK 50 KN4n 6/128 - INK BLACK';

    private const POVA = 'TECNO - HP 4G - POVA 7 LH7n 8/256 - GEEK BLACK';

    private const MEGAPAD = 'TECNO - HP 4G - MEGAPAD SE - T1102 4/128 - N - MOONDUST GREY';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['GMJKT' => 'PUSAT', 'GMBDG' => 'JAWA BARAT', 'GMCRB' => 'JAWA BARAT', 'GMSMG' => 'JAWA TENGAH', 'GBO' => null] as $code => $region) {
            WarehouseMapping::create(['csa_code' => $code, 'csa_name' => 'GUDANG '.$code, 'target_sheet' => $code, 'region' => $region, 'is_active' => true]);
        }
    }

    public function test_stock_report_and_uploaded_sales_become_transfers_within_region_and_from_the_hub(): void
    {
        $sales = $this->salesUpload([
            // Penjualan 30 hari (1–30 Oktober)
            ['GMBDG', 'FG-1', self::CAMON, 300],
            ['GMCRB', 'FG-1', self::CAMON, 30],
            ['GMJKT', 'FG-1', self::CAMON, 300],
            ['GMSMG', 'FG-1', self::CAMON, 60],
            ['GMCRB', 'FG-2', self::SPARK, 30],
            ['GMJKT', 'FG-3', self::POVA, 30],
            ['GMCRB', 'FG-4', self::MEGAPAD, 90],
            ['GMBDG', 'FG-4', self::MEGAPAD, 300],
            ['GBO', 'FG-1', self::CAMON, 500],
        ]);

        $allocation = $this->allocate([
            ['GMBDG', 'FG-1', self::CAMON, 'ANDROID', 10],
            ['#HPMBDG', 'FG-1', self::CAMON, 'ANDROID', 50],
            ['GMCRB', 'FG-1', self::CAMON, 'ANDROID', 100],
            ['GMJKT', 'FG-1', self::CAMON, 'ANDROID', 1000],
            ['GMCRB', 'FG-3', self::POVA, 'ANDROID', 100],
            ['GMCRB', 'FG-4', self::MEGAPAD, 'ANDROID', 100],
            ['#HPMCRB', 'FG-4', self::MEGAPAD, 'ANDROID', 600],
            // Tidak ikut: aksesoris, gudang tanpa region, saldo minus
            ['GMBDG', 'AC-1', 'TECNO - DATA CABLE - TCD-CC02 1M - N - WHITE', 'ACCESSORIES', 40],
            ['AKTIVA', 'FG-1', self::CAMON, 'ANDROID', 5],
            ['GBO', 'FG-1', self::CAMON, 'ANDROID', 3],
            ['GMSMG', 'FG-2', self::SPARK, 'ANDROID', -2],
        ]);

        $this->assertSame('2026-10-27', $allocation->stock_date->format('Y-m-d'));
        $this->assertSame($sales->id, $allocation->sales_import_id);
        $this->assertSame('2026-10-01', $allocation->sales_start->format('Y-m-d'));
        $this->assertTrue($allocation->usesStockReport());

        $bandung = $allocation->lines()->where('warehouse', 'GMBDG')->where('sku', 'FG-1')->sole();
        $this->assertSame('JAWA BARAT', $bandung->region);
        $this->assertSame('TECNO CAMON 50 CN5 8/128 · NEBULA TITANIUM', $bandung->product_name);
        $this->assertSame(10, $bandung->stock);
        // Gudang transit #HPMBDG = barang di jalan ke GMBDG
        $this->assertSame(50, $bandung->incoming);
        $this->assertSame(30, $bandung->sales_days);
        $this->assertSame(240, $bandung->need_qty);
        $this->assertFalse($allocation->lines()->whereIn('warehouse', ['AKTIVA', 'GBO', '#HPMBDG'])->exists());
        $this->assertFalse($allocation->lines()->where('sku', 'AC-1')->exists());

        // Semarang hanya bisa dari pusat; Bandung dari Cirebon (satu region) dulu, sisanya dari pusat
        $this->assertSame(60, $this->transfer($allocation, 'GMJKT', 'GMSMG', 'FG-1')->qty);
        $this->assertSame(70, $this->transfer($allocation, 'GMCRB', 'GMBDG', 'FG-1')->qty);
        $this->assertSame(170, $this->transfer($allocation, 'GMJKT', 'GMBDG', 'FG-1')->qty);

        // Laku tapi stok habis di mana-mana: kritis, jadi PO
        $soldOut = $allocation->lines()->where('warehouse', 'GMCRB')->where('sku', 'FG-2')->sole();
        $this->assertSame('critical', $soldOut->status);
        $this->assertSame(30, $soldOut->po_qty);

        // Pusat yang kekurangan menerima dari depo region mana pun (Cirebon tidak laku POVA 7)
        $this->assertSame(30, $this->transfer($allocation, 'GMCRB', 'GMJKT', 'FG-3')->qty);
        $this->assertSame(0, $allocation->lines()->where('warehouse', 'GMJKT')->where('sku', 'FG-3')->sole()->po_qty);

        // Gudang pengirim menyisakan stok fisik 30 hari; incoming-nya tidak dipakai untuk mengirim lebih banyak
        $this->assertSame(10, $this->transfer($allocation, 'GMCRB', 'GMBDG', 'FG-4')->qty);
    }

    public function test_detail_page_explains_the_sources_and_each_transfer(): void
    {
        $this->salesUpload([['GMBDG', 'FG-1', self::CAMON, 300], ['GMCRB', 'FG-1', self::CAMON, 30]]);
        $allocation = $this->allocate([
            ['GMBDG', 'FG-1', self::CAMON, 'ANDROID', 10],
            ['GMCRB', 'FG-1', self::CAMON, 'ANDROID', 100],
        ]);
        $transfer = $this->transfer($allocation, 'GMCRB', 'GMBDG', 'FG-1');
        $this->actingAsAdmin();

        Livewire::test(ViewStockAllocation::class, ['record' => $allocation->getRouteKey()])
            ->assertSee(['stok 27/10/2026', 'laku 01/10–30/10/2026 (30 hari)'])
            ->mountAction('howItWorks')
            ->assertMountedActionModalSee(['Laku per hari', 'gudang transit', 'PUSAT']);

        $component = Livewire::test(TransfersRelationManager::class, ['ownerRecord' => $allocation, 'pageClass' => ViewStockAllocation::class]);

        $this->assertSame(
            'GMBDG laku 10/hari, stok cukup 1 hari → kurang 290 unit untuk 30 hari. GMCRB stok cukup 100 hari → lebih 70 unit.',
            $component->instance()->reason($transfer),
        );
    }

    public function test_stock_report_needs_an_uploaded_sales_report_and_warehouse_regions(): void
    {
        try {
            $this->allocate([['GMBDG', 'FG-1', self::CAMON, 'ANDROID', 10]]);
            $this->fail('Tanpa laporan penjualan seharusnya ditolak');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Laporan Penjualan CSA', $exception->getMessage());
        }

        $this->salesUpload([['GMBDG', 'FG-1', self::CAMON, 300]]);
        WarehouseMapping::query()->update(['region' => null]);

        $this->expectExceptionMessage('Pemetaan Gudang');
        $this->allocate([['GMBDG', 'FG-1', self::CAMON, 'ANDROID', 10]]);
    }

    /**
     * Upload penjualan 1–30 Oktober yang sudah diproses: [kode gudang, kode barang, nama barang, qty].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $items
     */
    private function salesUpload(array $items): CsaImport
    {
        $import = CsaImport::create([
            'file_name' => 'penjualan.xlsx',
            'file_path' => '/tmp/penjualan.xlsx',
            'report_type' => CsaImport::REPORT_SALES,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-30',
            'status' => 'completed',
        ]);

        foreach ($items as [$warehouse, $sku, $name, $qty]) {
            CsaImportItem::create([
                'csa_import_id' => $import->id,
                'kode_gudang' => $warehouse,
                'nama_gudang' => 'GUDANG '.$warehouse,
                'kode_barang' => $sku,
                'nama_barang' => $name,
                'model' => $name,
                'brand' => 'TECNO',
                'is_unit' => true,
                'qty' => $qty,
                'nominal' => $qty * 1000000,
            ]);
        }

        return $import;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int}>  $rows  [kode gudang, kode item, nama barang, sort code, saldo]
     */
    private function allocate(array $rows): StockAllocation
    {
        $path = $this->stockReport($rows);

        try {
            return app(StockAllocationService::class)->import($path, 'Stok 27 Okt', 'stok opname.xlsx', 30, 2, null);
        } finally {
            @unlink($path);
        }
    }

    private function transfer(StockAllocation $allocation, string $from, string $to, string $sku): StockAllocationTransfer
    {
        return $allocation->transfers()->where('from_warehouse', $from)->where('to_warehouse', $to)->where('sku', $sku)->sole();
    }

    /**
     * File .xlsx berformat [XRSTOK02] Laporan Stock Opname Worksheet: baris saldo bernomor diikuti baris IMEI,
     * dan judul kolom yang diulang di tengah file.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int}>  $rows
     */
    private function stockReport(array $rows): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('stok_opname_').'.xlsx';
        $header = ['No', 'Kode', 'Nama Gudang', 'Kode Item', 'Nama Barang', 'Family', 'Sort Code', 'IMEI', 'Harga Pokok', 'Akumulasi Refund', 'Tgl Create', 'Sat', 'Saldo Akhir'];

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Lap, Stock Opname Worksheet');

        foreach (['[XRSTOK02] BAGUS3', 'LAPORAN STOCK OPNAME WORKSHEET', 'PT Media Selular Indonesia', 'Jl. Tuparev Kertawinangun', '-', 'Kode Item  :  AT-010047 s/d ZT04', 'Kelompok : - s/d ZT02', 'Fam.Code : * s/d ZTE', 'SortCode : -,ACCESSORIES,ANDROID', 'Per Tanggal: 27/10/2026', 'Gudang/ Site : (semua)'] as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }

        $writer->addRow(Row::fromValues($header));

        foreach ($rows as $index => [$warehouse, $sku, $name, $sortCode, $qty]) {
            if ($index === 2) {
                // Judul kolom terulang (halaman cetak berikutnya)
                $writer->addRow(Row::fromValues($header));
            }

            $writer->addRow(Row::fromValues([$index + 1, $warehouse, 'GUDANG '.$warehouse, $sku, $name, 'TECNO', $sortCode, null, 1000000, null, null, 'UNT', $qty]));
            $writer->addRow(Row::fromValues([null, $warehouse, 'GUDANG '.$warehouse, $sku, $name, null, null, '35874651052747'.$index, 1000000, 0, 46321, null, 1]));
        }

        $writer->close();

        return $path;
    }

    private function actingAsAdmin(): User
    {
        $permissions = ['ViewAny:StockAllocation', 'View:StockAllocation', 'Update:StockAllocation'];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }
}

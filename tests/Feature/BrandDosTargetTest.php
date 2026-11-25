<?php

namespace Tests\Feature;

use App\Filament\Resources\BrandDosTargets\Pages\ManageBrandDosTargets;
use App\Models\BrandDosTarget;
use App\Models\User;
use App\Services\StockAllocationService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\BrandDosTargetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BrandDosTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_populates_monitoring_dos_brand_targets_idempotently(): void
    {
        $this->seed(BrandDosTargetSeeder::class);
        $this->seed(BrandDosTargetSeeder::class);

        $this->assertSame(16, BrandDosTarget::count());
        $this->assertDatabaseHas('brand_dos_targets', [
            'company' => 'PT. MSI',
            'brand' => 'TECNO',
            'label' => 'TECNO ONLINE',
            'segment' => BrandDosTarget::SEGMENT_ONLINE,
            'target_dos' => 20,
        ]);
        $this->assertDatabaseHas('brand_dos_targets', [
            'company' => 'PT. MSI',
            'brand' => 'TECNO',
            'label' => 'TECNO OFFLINE',
            'segment' => BrandDosTarget::SEGMENT_OFFLINE,
            'target_dos' => 35,
        ]);
        $this->assertDatabaseHas('brand_dos_targets', [
            'company' => 'PT. MSI',
            'brand' => 'MOTOPAD',
            'target_dos' => 60,
        ]);
        $this->assertDatabaseHas('brand_dos_targets', [
            'company' => 'PT. MSI',
            'brand' => 'INFINIX',
            'target_dos' => null,
        ]);
    }

    public function test_resolver_matches_online_offline_region_brand_and_fallback(): void
    {
        $this->seed(BrandDosTargetSeeder::class);

        // Ubah REALME CJ jadi 50 untuk memverifikasi pencocokan per region
        BrandDosTarget::where('label', 'REALME CJ')->update(['target_dos' => 50]);

        $targets = BrandDosTarget::query()->where('is_active', true)->whereNotNull('target_dos')->get();

        // TECNO online vs offline berdasarkan kode gudang
        $this->assertSame(20, BrandDosTarget::resolveFromCollection($targets, 'TECNO', 'WMONL', 'ONLINE', 40));
        $this->assertSame(35, BrandDosTarget::resolveFromCollection($targets, 'TECNO', 'GMBDG', 'JAWA BARAT', 40));
        // Alias ejaan lama "TECHNO" tetap cocok ke TECNO
        $this->assertSame(35, BrandDosTarget::resolveFromCollection($targets, 'TECHNO', 'GMBDG', 'JAWA BARAT', 40));

        // REALME dibedakan per region WJ (JAWA BARAT) vs CJ (JAWA TENGAH)
        $this->assertSame(40, BrandDosTarget::resolveFromCollection($targets, 'REALME', 'GMBDG', 'JAWA BARAT', 30));
        $this->assertSame(50, BrandDosTarget::resolveFromCollection($targets, 'REALME', 'GMSMG', 'JAWA TENGAH', 30));

        // Brand umum
        $this->assertSame(45, BrandDosTarget::resolveFromCollection($targets, 'MOTOROLA', 'GMBDG', 'JAWA BARAT', 40));
        $this->assertSame(60, BrandDosTarget::resolveFromCollection($targets, 'MOTOPAD', 'GMBDG', 'JAWA BARAT', 40));
        $this->assertSame(30, BrandDosTarget::resolveFromCollection($targets, 'SAMSUNG', 'GMBDG', 'JAWA BARAT', 40));

        // Brand tanpa target_dos (INFINIX / ORAIMO) jatuh ke fallback default
        $this->assertSame(40, BrandDosTarget::resolveFromCollection($targets, 'INFINIX', 'GMBDG', 'JAWA BARAT', 40));
        $this->assertSame(40, BrandDosTarget::resolveFromCollection($targets, 'ORAIMO', 'GMBDG', 'JAWA BARAT', 40));
    }

    public function test_stock_allocation_uses_brand_dos_targets_when_enabled_and_respects_toggle_off(): void
    {
        $this->seed(BrandDosTargetSeeder::class);

        $rows = [
            ['GUDANG', 'REGION', 'BRAND', 'TYPE', 'WARNA', 'KETERANGAN', 'QTY', 'KODE_SKU', 'NAMA_BARANG'],
            // MOTOROLA (master target = 45 hari): laku 1/hari, stok 10 -> butuh 35 unit
            ['GMBDG', 'JAWA BARAT', 'MOTOROLA', 'G45', 'BLACK', 'STOCK', 10, 'SKU-MOTO', 'HP MOTOROLA G45'],
            ['GMBDG', 'JAWA BARAT', 'MOTOROLA', 'G45', 'BLACK', 'ST 30 HARI', 30, 'SKU-MOTO', 'HP MOTOROLA G45'],
            // SAMSUNG (master target = 30 hari): laku 1/hari, stok 10 -> butuh 20 unit
            ['GMBDG', 'JAWA BARAT', 'SAMSUNG', 'A16', 'BLUE', 'STOCK', 10, 'SKU-SAM', 'HP SAMSUNG A16'],
            ['GMBDG', 'JAWA BARAT', 'SAMSUNG', 'A16', 'BLUE', 'ST 30 HARI', 30, 'SKU-SAM', 'HP SAMSUNG A16'],
            // INFINIX (master target = null -> ikut default 40 hari): laku 1/hari, stok 10 -> butuh 30 unit
            ['GMBDG', 'JAWA BARAT', 'INFINIX', 'HOT 50', 'GRAY', 'STOCK', 10, 'SKU-INF', 'HP INFINIX HOT 50'],
            ['GMBDG', 'JAWA BARAT', 'INFINIX', 'HOT 50', 'GRAY', 'ST 30 HARI', 30, 'SKU-INF', 'HP INFINIX HOT 50'],
        ];

        $path = $this->workbook($rows);

        try {
            $allocation = app(StockAllocationService::class)->import($path, 'Test Brand DOS', 'test.xlsx', 40, 2, null, true);

            $moto = $allocation->lines()->where('sku', 'SKU-MOTO')->sole();
            $sam = $allocation->lines()->where('sku', 'SKU-SAM')->sole();
            $inf = $allocation->lines()->where('sku', 'SKU-INF')->sole();

            $this->assertSame('MOTOROLA', $moto->brand);
            $this->assertSame(45, $moto->target_dos);
            $this->assertSame(35, $moto->need_qty);

            $this->assertSame('SAMSUNG', $sam->brand);
            $this->assertSame(30, $sam->target_dos);
            $this->assertSame(20, $sam->need_qty);

            $this->assertSame('INFINIX', $inf->brand);
            $this->assertSame(40, $inf->target_dos);
            $this->assertSame(30, $inf->need_qty);

            // Jika use_brand_targets dimatikan, semua brand ikut target global (30 hari)
            $flatAllocation = app(StockAllocationService::class)->import($path, 'Test Flat DOS', 'test.xlsx', 30, 2, null, false);
            $this->assertSame(30, $flatAllocation->lines()->where('sku', 'SKU-MOTO')->sole()->target_dos);
            $this->assertSame(20, $flatAllocation->lines()->where('sku', 'SKU-MOTO')->sole()->need_qty);
        } finally {
            @unlink($path);
        }
    }

    public function test_admin_can_manage_master_target_dos_in_filament(): void
    {
        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        foreach (['ViewAny:BrandDosTarget', 'Create:BrandDosTarget', 'Update:BrandDosTarget', 'Delete:BrandDosTarget'] as $permission) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        Livewire::test(ManageBrandDosTargets::class)
            ->assertOk()
            ->callAction('create', data: [
                'company' => 'pt. msi',
                'label' => 'tecno online',
                'target_dos' => 20,
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('brand_dos_targets', [
            'company' => 'PT. MSI',
            'brand' => 'TECNO',
            'label' => 'TECNO ONLINE',
            'segment' => BrandDosTarget::SEGMENT_ONLINE,
            'target_dos' => 20,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_import_monitoring_dos_excel_in_filament(): void
    {
        Storage::fake('local');

        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        foreach (['ViewAny:BrandDosTarget', 'Create:BrandDosTarget', 'Update:BrandDosTarget'] as $permission) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        // Format persis seperti tabel Excel Monitoring DOS (termasuk judul di baris 1, PT.MSI tanpa spasi, baris kosong, dan baris TOTAL)
        $path = $this->workbook([
            ['MONITORING DOS 23 nov', '', '', ''],
            ['COMPANY', 'BRAND', 'TARGET DOS BY BRAND', 'ACTUAL DOS Periode OKT'],
            ['PT.MSI', 'TECNO ONLINE', 20, 30],
            ['PT.MSI', 'TECNO OFFLINE', 35, 51],
            ['PT.MSI', 'MOTOROLA', 45, 556],
            ['PT.MSI', 'infinix', '', 65],
            ['CV.TOP', 'REALME WJ', 40, 18],
            ['PT.SMI', 'oraimo online', '', 176],
            ['', 'TOTAL', 40, 107],
        ]);

        try {
            Livewire::test(ManageBrandDosTargets::class)
                ->callAction('importExcel', data: [
                    'file' => UploadedFile::fake()->createWithContent('Master_Target_DOS.xlsx', (string) file_get_contents($path)),
                    'replace_existing' => true,
                ])
                ->assertHasNoFormErrors()
                ->assertNotified('6 data Target DOS berhasil diimport');
        } finally {
            @unlink($path);
        }

        $this->assertSame(6, BrandDosTarget::count());
        $this->assertDatabaseHas('brand_dos_targets', [
            'company' => 'PT. MSI',
            'brand' => 'TECNO',
            'label' => 'TECNO ONLINE',
            'segment' => BrandDosTarget::SEGMENT_ONLINE,
            'target_dos' => 20,
        ]);
        $this->assertDatabaseHas('brand_dos_targets', [
            'company' => 'PT. MSI',
            'brand' => 'INFINIX',
            'label' => 'INFINIX',
            'target_dos' => null,
        ]);
        $this->assertDatabaseHas('brand_dos_targets', [
            'company' => 'CV. TOP',
            'brand' => 'REALME',
            'label' => 'REALME WJ',
            'region_scope' => 'JAWA BARAT',
            'target_dos' => 40,
        ]);
        $this->assertDatabaseHas('brand_dos_targets', [
            'company' => 'PT. SMI',
            'brand' => 'ORAIMO',
            'label' => 'ORAIMO ONLINE',
            'segment' => BrandDosTarget::SEGMENT_ONLINE,
            'target_dos' => null,
        ]);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function workbook(array $rows): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('brand_dos_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Data');

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Resources\StockAllocations\Pages\ListStockAllocations;
use App\Filament\Resources\StockAllocations\Pages\ViewStockAllocation;
use App\Filament\Resources\StockAllocations\RelationManagers\LinesRelationManager;
use App\Filament\Resources\StockAllocations\RelationManagers\TransfersRelationManager;
use App\Models\StockAllocation;
use App\Models\StockAllocationLine;
use App\Models\StockAllocationTransfer;
use App\Models\User;
use App\Services\StockAllocationExporter;
use App\Services\StockAllocationService;
use App\Services\XlsxReader;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockAllocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Jawa Barat, 1 SKU:
     * - GTBDG stok 10, laku 10/hari (DOS 1)  → butuh 290 untuk 30 hari
     * - GTKRW stok 50, laku 2/hari (DOS 25)  → butuh 10
     * - GTC   stok 400, laku 1/hari (DOS 400) → lebih 370
     * - GTTSK stok 20, tidak ada penjualan   → lebih 20
     * Jawa Tengah GTSMG stok 5, laku 1/hari → butuh 25 (tidak boleh diambil dari Jawa Barat).
     * Gudang retur tidak ikut dihitung.
     *
     * @return list<list<mixed>>
     */
    private function dataRows(): array
    {
        $row = fn (string $gudang, string $region, string $keterangan, int $qty, string $sku = '50001'): array => [
            $gudang, $region, 'REALME', 'NOTE 80 4GB 64GB', 'GLACIER BLUE', 'WJ', $keterangan, $qty, $sku, 'HP REALME NOTE 80 4GB 64GB GLACIER BLUE WJ',
        ];

        return [
            ['GUDANG', 'REGION', 'BRAND', 'TYPE', 'WARNA', 'PRODUK_CEK', 'KETERANGAN', 'QTY', 'KODE_SKU', 'NAMA_BARANG'],
            $row('GTBDG', 'JAWA BARAT', 'STOCK', 10),
            $row('GTBDG', 'JAWA BARAT', 'ST 30 HARI GS', 300),
            $row('GTKRW', 'JAWA BARAT', 'STOCK', 50),
            $row('GTKRW', 'JAWA BARAT', 'ST 30 HARI GS', 60),
            $row('GTC', 'JAWA BARAT', 'STOCK', 400),
            $row('GTC', 'JAWA BARAT', 'ST 30 HARI GS', 30),
            $row('GTTSK', 'JAWA BARAT', 'STOCK', 20),
            $row('GTSMG', 'JAWA TENGAH', 'STOCK', 5),
            $row('GTSMG', 'JAWA TENGAH', 'ST 30 HARI GS', 30),
            $row('GTKK CRB', 'RETUR', 'STOCK', 100),
        ];
    }

    private function allocate(int $targetDos = 30, int $minTransfer = 2): StockAllocation
    {
        $path = $this->workbook(['Parameter' => [['Target Coverage', 30]], 'Data' => $this->dataRows()]);

        try {
            return app(StockAllocationService::class)->import($path, 'DOS Oktober', 'DOS Oktober.xlsx', $targetDos, $minTransfer, null);
        } finally {
            @unlink($path);
        }
    }

    private function transfer(StockAllocation $allocation, string $from, string $to): StockAllocationTransfer
    {
        return $allocation->transfers()->where('from_warehouse', $from)->where('to_warehouse', $to)->sole();
    }

    public function test_critical_warehouse_is_filled_from_overstocked_warehouses_in_the_same_region(): void
    {
        $allocation = $this->allocate();

        $this->assertSame(20, $this->transfer($allocation, 'GTTSK', 'GTBDG')->qty);
        $this->assertSame(270, $this->transfer($allocation, 'GTC', 'GTBDG')->qty);
        $this->assertSame(10, $this->transfer($allocation, 'GTC', 'GTKRW')->qty);
        $this->assertSame(3, $allocation->transfer_count);
        $this->assertSame(300, $allocation->transfer_qty);

        // Kebutuhan PO 290 + 10 + 25 = 325; yang tersisa hanya Jawa Tengah karena transfer tidak lintas region
        $this->assertSame(325, $allocation->po_qty_before);
        $this->assertSame(25, $allocation->po_qty_after);
        $this->assertSame(5, $allocation->warehouse_count);
        $this->assertFalse($allocation->lines()->where('region', 'RETUR')->exists());

        $bandung = $allocation->lines()->where('warehouse', 'GTBDG')->sole();
        $this->assertSame('critical', $bandung->status);
        $this->assertEqualsWithDelta(1.0, $bandung->dos_before, 0.01);
        $this->assertEqualsWithDelta(30.0, $bandung->dos_after, 0.01);
        $this->assertSame(0, $bandung->po_qty);

        // Gudang pengirim tetap menyisakan stok di atas target 30 hari
        $cirebon = $allocation->lines()->where('warehouse', 'GTC')->sole();
        $this->assertSame('overstock', $cirebon->status);
        $this->assertSame(280, $cirebon->transfer_out);
        $this->assertEqualsWithDelta(120.0, $cirebon->dos_after, 0.01);

        $this->assertSame('no_sales', $allocation->lines()->where('warehouse', 'GTTSK')->sole()->status);
        $this->assertSame(25, $allocation->lines()->where('warehouse', 'GTSMG')->sole()->po_qty);
    }

    public function test_transfers_below_the_minimum_quantity_are_not_suggested(): void
    {
        $allocation = $this->allocate(minTransfer: 25);

        // 20 unit dari GTTSK dan kebutuhan 10 unit GTKRW di bawah minimal 25 unit
        $this->assertSame(1, $allocation->transfer_count);
        $this->assertSame(290, $this->transfer($allocation, 'GTC', 'GTBDG')->qty);
        $this->assertSame(10 + 25, $allocation->po_qty_after);
    }

    public function test_rejected_and_changed_transfers_update_the_remaining_po(): void
    {
        $allocation = $this->allocate();
        $service = app(StockAllocationService::class);

        $service->decide($allocation, [$this->transfer($allocation, 'GTC', 'GTBDG')], StockAllocationTransfer::STATUS_REJECTED, null);
        $allocation->refresh();

        $this->assertSame(2, $allocation->transfer_count);
        $this->assertSame(30, $allocation->transfer_qty);
        $this->assertSame(25 + 270, $allocation->po_qty_after);

        $toKarawang = $this->transfer($allocation, 'GTC', 'GTKRW');
        $service->changeQty($toKarawang, 4, null);

        $this->assertSame(StockAllocationTransfer::STATUS_APPROVED, $toKarawang->refresh()->status);
        $this->assertSame(10, $toKarawang->suggested_qty);
        $this->assertSame(25 + 270 + 6, $allocation->refresh()->po_qty_after);
    }

    public function test_file_without_the_required_columns_is_rejected(): void
    {
        $path = $this->workbook(['Sheet1' => [['NAMA', 'ALAMAT'], ['A', 'B']]]);

        try {
            $this->expectException(RuntimeException::class);
            app(StockAllocationService::class)->read($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_upload_calculates_and_opens_the_result(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin(['ViewAny:StockAllocation', 'View:StockAllocation', 'Create:StockAllocation']);
        $path = $this->workbook(['Data' => $this->dataRows()]);

        Livewire::test(ListStockAllocations::class)
            ->callAction('upload', data: [
                'file' => UploadedFile::fake()->createWithContent('DOS Oktober.xlsx', (string) file_get_contents($path)),
                'target_dos' => 30,
                'min_transfer_qty' => 2,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Alokasi selesai dihitung')
            ->assertRedirect();

        @unlink($path);

        $allocation = StockAllocation::sole();
        $this->assertSame('DOS Oktober', $allocation->name);
        $this->assertSame(300, $allocation->transfer_qty);
        $this->assertSame([], Storage::disk('local')->allFiles('stock-allocation-imports'));
    }

    public function test_upload_is_hidden_without_create_permission(): void
    {
        $this->actingAsAdmin(['ViewAny:StockAllocation']);

        Livewire::test(ListStockAllocations::class)->assertActionHidden('upload');
    }

    public function test_detail_page_shows_summary_and_transfers_can_be_approved(): void
    {
        $allocation = $this->allocate();
        $this->actingAsAdmin(['ViewAny:StockAllocation', 'View:StockAllocation', 'Update:StockAllocation']);

        Livewire::test(ViewStockAllocation::class, ['record' => $allocation->getRouteKey()])
            ->assertOk()
            ->assertSee('Saran Transfer')
            ->assertSee('300 unit')
            ->assertSee('325 unit');

        $transfer = $this->transfer($allocation, 'GTC', 'GTBDG');

        Livewire::test(TransfersRelationManager::class, ['ownerRecord' => $allocation, 'pageClass' => ViewStockAllocation::class])
            ->assertCanSeeTableRecords($allocation->transfers)
            ->callAction(TestAction::make('approve')->table($transfer))
            ->assertDispatched('stock-allocation-updated');

        $this->assertSame(StockAllocationTransfer::STATUS_APPROVED, $transfer->refresh()->status);

        Livewire::test(LinesRelationManager::class, ['ownerRecord' => $allocation, 'pageClass' => ViewStockAllocation::class])
            ->assertCanSeeTableRecords($allocation->lines)
            ->filterTable('needs_po')
            ->assertCanSeeTableRecords($allocation->lines()->where('po_qty', '>', 0)->get())
            ->assertCanNotSeeTableRecords($allocation->lines()->where('po_qty', 0)->get());
    }

    public function test_transfers_cannot_be_approved_without_update_permission(): void
    {
        $allocation = $this->allocate();
        $this->actingAsAdmin(['ViewAny:StockAllocation', 'View:StockAllocation']);

        Livewire::test(TransfersRelationManager::class, ['ownerRecord' => $allocation, 'pageClass' => ViewStockAllocation::class])
            ->assertActionHidden(TestAction::make('approve')->table($this->transfer($allocation, 'GTC', 'GTBDG')));
    }

    public function test_recalculate_uses_the_new_parameters(): void
    {
        $allocation = $this->allocate();
        $this->actingAsAdmin(['ViewAny:StockAllocation', 'View:StockAllocation', 'Update:StockAllocation']);

        Livewire::test(ViewStockAllocation::class, ['record' => $allocation->getRouteKey()])
            ->callAction('recalculate', data: ['target_dos' => 30, 'min_transfer_qty' => 25])
            ->assertHasNoFormErrors();

        $allocation->refresh();
        $this->assertSame(25, $allocation->min_transfer_qty);
        $this->assertSame(1, $allocation->transfer_count);
    }

    public function test_export_contains_transfers_lines_and_po(): void
    {
        $allocation = $this->allocate();
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('alokasi_').'.xlsx';

        app(StockAllocationExporter::class)->write($allocation, $path);

        $reader = new XlsxReader;
        $strings = $reader->sharedStrings($path);
        $sheets = $reader->sheets($path);
        $count = fn (string $sheet): int => iterator_count($reader->iterateRows($path, $sheets[$sheet], $strings)) - 1;

        $this->assertSame(['Saran Transfer', 'Per Gudang', 'Rekomendasi PO'], array_keys($sheets));
        $this->assertSame(3, $count('Saran Transfer'));
        $this->assertSame(StockAllocationLine::count(), $count('Per Gudang'));
        $this->assertSame(1, $count('Rekomendasi PO'));

        @unlink($path);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingAsAdmin(array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * @param  array<string, list<list<mixed>>>  $tabs
     */
    private function workbook(array $tabs): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('stok_').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);

        foreach (array_keys($tabs) as $index => $name) {
            if ($index > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $writer->getCurrentSheet()->setName($name);

            foreach ($tabs[$name] as $values) {
                $writer->addRow(Row::fromValues($values));
            }
        }

        $writer->close();

        return $path;
    }
}

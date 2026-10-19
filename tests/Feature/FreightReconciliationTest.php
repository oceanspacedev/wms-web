<?php

namespace Tests\Feature;

use App\Filament\Resources\FreightInvoices\Pages\CreateFreightInvoice;
use App\Filament\Resources\FreightInvoices\Pages\ViewFreightInvoice;
use App\Filament\Resources\FreightInvoices\RelationManagers\ItemsRelationManager;
use App\Jobs\ProcessFreightReconciliationJob;
use App\Models\CsaImport;
use App\Models\CsaShipment;
use App\Models\Expedition;
use App\Models\ExpeditionRateCard;
use App\Models\FreightInvoice;
use App\Models\User;
use App\Services\FreightReconciliationService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\ExpeditionRateCardSeeder;
use Database\Seeders\ExpeditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FreightReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected Expedition $expedition;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ExpeditionSeeder::class);
        $this->seed(ExpeditionRateCardSeeder::class);

        $this->expedition = Expedition::where('code', 'JNT')->firstOrFail();
        $this->user = User::factory()->create();
    }

    public function test_matched_shipment_audited_correctly(): void
    {
        $import = CsaImport::create([
            'user_id' => $this->user->id,
            'file_name' => 'sales.xlsx',
            'file_path' => '/tmp/sales.xlsx',
            'status' => 'completed',
        ]);

        $shipment = CsaShipment::create([
            'csa_import_id' => $import->id,
            'no_sj' => 'SJ-CRB-001',
            'no_resi_awb' => 'JNT12345678',
            'kode_gudang' => 'GMCRB',
            'target_sheet' => 'CIREBON',
            'nama_kota' => 'INDRAMAYU',
            'berat' => 2.0,
            'total_nominal_sj' => 1000000.0,
        ]);

        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-TEST-001',
            'invoice_date' => now(),
            'status' => 'processing',
        ]);

        // Rate card CIREBON -> INDRAMAYU is Rp 8,000/kg with 0.2% insurance
        // Expected base = 2kg * 8,000 = 16,000
        // Expected insurance = 1,000,000 * 0.2% = 2,000
        // Expected total = 18,000

        $rawItems = [
            [
                'no_resi_awb' => 'JNT12345678',
                'no_sj' => 'SJ-CRB-001',
                'origin_depo' => 'CIREBON',
                'destination_city' => 'INDRAMAYU',
                'billed_weight' => 2.0,
                'billed_rate' => 8000.0,
                'billed_insurance' => 2000.0,
                'billed_total' => 18000.0,
            ],
        ];

        $service = new FreightReconciliationService;
        $result = $service->reconcile($invoice, $rawItems);

        $this->assertEquals('audited', $result->status);
        $this->assertEquals(1, $result->total_items_count);
        $this->assertEquals(1, $result->matched_count);
        $this->assertEquals(0, $result->discrepancy_count);
        $this->assertEquals(18000.0, $result->total_billed_amount);
        $this->assertEquals(18000.0, $result->total_approved_amount);
        $this->assertEquals(0.0, $result->total_discrepancy_amount);

        $item = $result->items->first();
        $this->assertEquals('matched', $item->audit_status);
        $this->assertEquals($shipment->id, $item->csa_shipment_id);
    }

    public function test_detects_unrecognized_ghost_shipment(): void
    {
        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-GHOST-001',
            'invoice_date' => now(),
            'status' => 'processing',
        ]);

        $rawItems = [
            [
                'no_resi_awb' => 'FIKTIF-999999',
                'no_sj' => 'SJ-UNKNOWN',
                'origin_depo' => 'CIREBON',
                'destination_city' => 'JAKARTA',
                'billed_weight' => 5.0,
                'billed_rate' => 15000.0,
                'billed_total' => 75000.0,
            ],
        ];

        $service = new FreightReconciliationService;
        $result = $service->reconcile($invoice, $rawItems);

        $this->assertEquals(1, $result->unrecognized_count);
        $this->assertEquals(75000.0, $result->total_billed_amount);
        $this->assertEquals(0.0, $result->total_approved_amount);
        $this->assertEquals(75000.0, $result->total_discrepancy_amount);

        $item = $result->items->first();
        $this->assertEquals('unrecognized', $item->audit_status);
        $this->assertEquals(75000.0, $item->discrepancy_amount);
    }

    public function test_detects_rate_overcharge_and_weight_markup(): void
    {
        $import = CsaImport::create([
            'user_id' => $this->user->id,
            'file_name' => 'sales.xlsx',
            'file_path' => '/tmp/sales.xlsx',
            'status' => 'completed',
        ]);

        // Real weight in warehouse is 2 kg
        CsaShipment::create([
            'csa_import_id' => $import->id,
            'no_sj' => 'SJ-CRB-DISC',
            'no_resi_awb' => 'JNT88889999',
            'kode_gudang' => 'GMCRB',
            'target_sheet' => 'CIREBON',
            'nama_kota' => 'TASIKMALAYA',
            'berat' => 2.0,
            'total_nominal_sj' => 5000000.0,
        ]);

        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-DISC-001',
            'invoice_date' => now(),
            'status' => 'processing',
        ]);

        // PKS rate for CIREBON -> TASIKMALAYA is Rp 10,000/kg
        // Actual weight: 2 kg -> Expected base: 20,000, insurance: 10,000 -> Expected total: 30,000
        // Expedition billed: 4 kg (+2 kg markup!) at Rp 15,000/kg (+5,000 overcharge!) -> Billed total: 60,000

        $rawItems = [
            [
                'no_resi_awb' => 'JNT88889999',
                'no_sj' => 'SJ-CRB-DISC',
                'origin_depo' => 'CIREBON',
                'destination_city' => 'TASIKMALAYA',
                'billed_weight' => 4.0,
                'billed_rate' => 15000.0,
                'billed_insurance' => 0.0,
                'billed_total' => 60000.0,
            ],
        ];

        $service = new FreightReconciliationService;
        $result = $service->reconcile($invoice, $rawItems);

        $this->assertEquals(1, $result->discrepancy_count);
        $this->assertEquals(60000.0, $result->total_billed_amount);
        $this->assertEquals(30000.0, $result->total_approved_amount);
        $this->assertEquals(30000.0, $result->total_discrepancy_amount);

        $item = $result->items->first();
        $this->assertEquals('discrepancy_both', $item->audit_status);
        $this->assertEquals(2.0, $item->weight_discrepancy);
        $this->assertEquals(30000.0, $item->discrepancy_amount);
    }

    public function test_expedition_invoice_file_is_checked_against_contract_rates(): void
    {
        $expedition = Expedition::create(['name' => '21 EXPRES', 'code' => '21EXPRES', 'is_active' => true]);
        foreach ([
            ['BOGOR', 'DARAT', 1500, 10],
            ['BEKASI', 'UDARA', 8000, 0],
            ['PALEMBANG', 'DARAT', 3000, 10],
        ] as [$city, $service, $rate, $minKg]) {
            ExpeditionRateCard::create([
                'expedition_id' => $expedition->id, 'origin_depo' => 'JAKARTA', 'destination_city' => $city,
                'destination_district' => $city, 'service_type' => $service, 'rate_per_kg' => $rate, 'min_kg' => $minKg,
                'insurance_rate_percent' => 0, 'is_active' => true,
            ]);
        }

        $this->shipment('R-BOGOR', 'SJ-1', 17);
        $this->shipment('R-BEKASI', 'SJ-2', 1.5);
        $this->shipment('R-PLG', 'SJ-3', 1);
        $this->shipment('R-IDM', 'SJ-4', 3);
        // Satu resi berisi dua SJ: beratnya dijumlah
        $this->shipment('R-GABUNG', 'SJ-5', 14.52);
        $this->shipment('R-GABUNG', 'SJ-6', 10.22);

        // Format invoice 21 Express: kolom CEK TARIF isian admin dan Tariff Total bukan tarif per kg
        $path = $this->invoiceFile([
            ['Date', 'Shipment No', 'Destination', 'Gross Weight', 'Chargeable Weight', 'Service Type', 'Tariff', 'CEK TARIF', 'Tariff Total', 'Total IDR', 'Nomor sj', 'Amount'],
            ['46266.24', 'R-BOGOR', 'BOGOR', 17.34, 18, 'DARAT', 1500, 'SESUAI', 27000, 27000, 'SJ-1', 13000000],
            ['46266.25', 'R-BEKASI', 'BEKASI', 1.46, 2, 'ECO', 7000, 'SESUAI', 14000, 14000, 'SJ-2', 568800],
            ['46266.24', 'R-PLG', 'PALEMBANG', 1, 1, 'ECO', 31000, 'REVISI DARAT', 31000, 31000, 'SJ-3', 0],
            ['46266.24', 'R-IDM', 'INDRAMAYU-INDRAMAYU JAWA BARAT', 3.34, 10, 'DARAT', 5000, 'SESUAI', 50000, 50000, 'SJ-4', 2019000],
            ['46266.24', 'R-GABUNG', 'BOGOR', 20, 20, 'DARAT', 1500, 'SESUAI', 30000, 30000, '-', 0],
            ['', '', '', '', '', '', '', '', '', 'Grand Total', '', 152000],
        ]);

        $service = new FreightReconciliationService;
        $rows = $service->parseInvoiceFile($path);
        @unlink($path);

        $this->assertCount(5, $rows);
        $this->assertSame(1500.0, $rows[0]['billed_rate']);
        $this->assertSame(27000.0, $rows[0]['billed_total']);
        $this->assertSame(18.0, $rows[0]['billed_weight']);
        $this->assertSame('ECO', $rows[1]['service_type']);

        $invoice = FreightInvoice::create([
            'expedition_id' => $expedition->id,
            'invoice_number' => 'INV-21-SEP',
            'invoice_date' => now(),
            'status' => 'processing',
        ]);
        $items = $service->reconcile($invoice, $rows)->items->keyBy('no_resi_awb');

        $this->assertSame('matched', $items['R-BOGOR']->audit_status);
        $this->assertSame('JAKARTA', $items['R-BOGOR']->origin_depo);
        // ECO dibandingkan ke tarif UDARA; lebih murah dari kontrak tetap sesuai
        $this->assertSame('matched', $items['R-BEKASI']->audit_status);
        $this->assertEquals(8000, $items['R-BEKASI']->agreed_rate);
        // ECO tanpa tarif UDARA, padahal DARAT (min 10 kg × Rp3.000 = Rp30.000) lebih murah
        $this->assertSame('revisi_darat', $items['R-PLG']->audit_status);
        $this->assertEquals(1000, $items['R-PLG']->discrepancy_amount);
        $this->assertSame('missing_rate', $items['R-IDM']->audit_status);
        $this->assertSame('matched', $items['R-GABUNG']->audit_status);
        $this->assertEquals(24.74, $items['R-GABUNG']->actual_weight);

        // Halaman detail menampilkan kolom CEK TARIF per resi
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']));
        $this->actingAs($admin);

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewFreightInvoice::class])
            ->assertCanSeeTableRecords($invoice->items)
            ->assertSee('REVISI DARAT')
            ->assertSee('TARIF BELUM ADA')
            ->filterTable('audit_status', 'revisi_darat')
            ->assertCanSeeTableRecords([$items['R-PLG']])
            ->assertCanNotSeeTableRecords([$items['R-BOGOR']]);
    }

    public function test_uploading_an_invoice_only_needs_the_expedition_and_file(): void
    {
        Queue::fake();
        Storage::fake('local');
        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        foreach (['ViewAny:FreightInvoice', 'View:FreightInvoice', 'Create:FreightInvoice'] as $permission) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        Livewire::test(CreateFreightInvoice::class)
            ->fillForm([
                'expedition_id' => $this->expedition->id,
                'invoice_file' => UploadedFile::fake()->create('PT. Satu Media Indonesia September 2026 - 21 Express.xlsx', 50, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Nomor invoice kosong: diambil dari nama file
        $invoice = FreightInvoice::sole();
        $this->assertSame('PT. Satu Media Indonesia September 2026 - 21 Express', $invoice->invoice_number);
        $this->assertSame(now()->toDateString(), $invoice->invoice_date->toDateString());
        Queue::assertPushed(ProcessFreightReconciliationJob::class);
    }

    private function shipment(string $resi, string $noSj, float $berat): CsaShipment
    {
        return CsaShipment::create([
            'no_sj' => $noSj,
            'no_resi_awb' => $resi,
            'kode_gudang' => 'GMJKT',
            'target_sheet' => 'JAKARTA PIK',
            'nama_kota' => 'BOGOR',
            'berat' => $berat,
            'total_nominal_sj' => 1000000,
        ]);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function invoiceFile(array $rows): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('invoice_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $values) {
            $writer->addRow(Row::fromValues($values));
        }

        $writer->close();

        return $path;
    }
}

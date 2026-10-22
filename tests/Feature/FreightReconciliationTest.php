<?php

namespace Tests\Feature;

use App\Filament\Resources\FreightInvoices\Pages\CreateFreightInvoice;
use App\Filament\Resources\FreightInvoices\Pages\ListFreightInvoices;
use App\Filament\Resources\FreightInvoices\Pages\ViewFreightInvoice;
use App\Filament\Resources\FreightInvoices\RelationManagers\ItemsRelationManager;
use App\Jobs\ProcessFreightReconciliationJob;
use App\Models\Expedition;
use App\Models\ExpeditionRateCard;
use App\Models\FreightInvoice;
use App\Models\FreightInvoiceItem;
use App\Models\User;
use App\Services\FreightReconciliationService;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\ExpeditionRateCardSeeder;
use Database\Seeders\ExpeditionSeeder;
use Filament\Actions\Testing\TestAction;
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

    public function test_line_matching_the_contract_rate_is_paid_in_full(): void
    {
        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-TEST-001',
            'invoice_date' => now(),
            'status' => 'processing',
        ]);

        // Tarif Kontrak CIREBON -> INDRAMAYU Rp 8.000/kg; ditagih 2 kg × 8.000 + asuransi 2.000 = 18.000
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
        $this->assertEquals(8000, $item->agreed_rate);
    }

    public function test_route_without_contract_rate_is_unverified(): void
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

        // Tarif CIREBON -> JAKARTA belum ada di Tarif Kontrak: belum bisa dinilai, dipisah dari koreksi
        $this->assertEquals(1, $result->unrecognized_count);
        $this->assertEquals(75000.0, $result->total_billed_amount);
        $this->assertEquals(0.0, $result->total_approved_amount);
        $this->assertEquals(0.0, $result->total_discrepancy_amount);
        $this->assertEquals(75000.0, $result->total_unverified_amount);

        $item = $result->items->first();
        $this->assertEquals('missing_rate', $item->audit_status);
        $this->assertEquals(0.0, $item->discrepancy_amount);
    }

    public function test_detects_rate_overcharge_and_weight_markup(): void
    {
        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-DISC-001',
            'invoice_date' => now(),
            'origin_city' => 'CIREBON',
            'status' => 'processing',
        ]);

        // Tarif Kontrak CIREBON -> TASIKMALAYA Rp 10.000/kg (min 1 kg); timbang ekspedisi 2 kg
        // Ditagih 4 kg (+2 kg) × Rp 15.000/kg (+5.000) = 60.000; seharusnya 2 kg × 10.000 = 20.000
        $rawItems = [
            [
                'no_resi_awb' => 'JNT88889999',
                'no_sj' => 'SJ-CRB-DISC',
                'destination_city' => 'TASIKMALAYA',
                'gross_weight' => 2.0,
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
        $this->assertEquals(20000.0, $result->total_approved_amount);
        $this->assertEquals(40000.0, $result->total_discrepancy_amount);

        $item = $result->items->first();
        $this->assertEquals('discrepancy_both', $item->audit_status);
        $this->assertEquals('CIREBON', $item->origin_depo);
        $this->assertEquals(2.0, $item->weight_discrepancy);
        $this->assertEquals(40000.0, $item->discrepancy_amount);
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

        // Format invoice 21 Express: kolom CEK TARIF isian admin dan Tariff Total bukan tarif per kg
        $path = $this->invoiceFile([
            ['Date', 'Shipment No', 'Destination', 'Gross Weight', 'Chargeable Weight', 'Service Type', 'Tariff', 'CEK TARIF', 'Tariff Total', 'Total IDR', 'Nomor sj', 'Amount'],
            ['46266.24', 'R-BOGOR', 'BOGOR', 17.34, 18, 'DARAT', 1500, 'SESUAI', 27000, 27000, 'SJ-1', 13000000],
            ['46266.25', 'R-BEKASI', 'BEKASI', 1.46, 2, 'ECO', 7000, 'SESUAI', 14000, 14000, 'SJ-2', 568800],
            ['46266.24', 'R-PLG', 'PALEMBANG', 1, 1, 'ECO', 31000, 'REVISI DARAT', 31000, 31000, 'SJ-3', 0],
            ['46266.24', 'R-IDM', 'INDRAMAYU-INDRAMAYU JAWA BARAT', 3.34, 10, 'DARAT', 5000, 'SESUAI', 50000, 50000, 'SJ-4', 2019000],
            // Timbang 6 kg tapi ditagih 20 kg (minimum hanya 10 kg)
            ['46266.24', 'R-BERAT', 'BOGOR', 6, 20, 'DARAT', 1500, 'SESUAI', 30000, 30000, '-', 0],
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

        // Invoice tidak mencantumkan kota asal: dipakai Kota Asal yang dipilih saat upload
        $invoice = FreightInvoice::create([
            'expedition_id' => $expedition->id,
            'invoice_number' => 'INV-21-SEP',
            'invoice_date' => now(),
            'origin_city' => 'JAKARTA',
            'status' => 'processing',
        ]);
        $items = $service->reconcile($invoice, $rows)->items->keyBy('no_resi_awb');

        $this->assertSame('matched', $items['R-BOGOR']->audit_status);
        $this->assertSame('JAKARTA', $items['R-BOGOR']->origin_depo);
        // Timbang 17,34 kg dibulatkan jadi 18 kg: sesuai berarti dibayar penuh
        $this->assertEquals(27000, $items['R-BOGOR']->expected_total);
        $this->assertEquals(0, $items['R-BOGOR']->discrepancy_amount);
        $this->assertEquals(17.34, $items['R-BOGOR']->actual_weight);
        // ECO dibandingkan ke tarif UDARA; lebih murah dari kontrak tetap sesuai
        $this->assertSame('matched', $items['R-BEKASI']->audit_status);
        $this->assertEquals(8000, $items['R-BEKASI']->agreed_rate);
        // ECO tanpa tarif UDARA, padahal DARAT (min 10 kg × Rp3.000 = Rp30.000) lebih murah
        $this->assertSame('revisi_darat', $items['R-PLG']->audit_status);
        $this->assertEquals(1000, $items['R-PLG']->discrepancy_amount);
        // Tarif rute belum ada: belum terverifikasi
        $this->assertSame('missing_rate', $items['R-IDM']->audit_status);
        $this->assertEquals(50000, $invoice->fresh()->total_unverified_amount);
        // Resi yang tarifnya belum ada tidak ikut dihitung "perlu dicek" (revisi darat + berat lebih saja)
        $this->assertSame(1, $invoice->fresh()->unrecognized_count);
        $this->assertSame(2, $invoice->fresh()->discrepancy_count);
        // Seharusnya max(6 kg, min 10 kg) = 10 kg × 1.500 = 15.000
        $this->assertSame('discrepancy_weight', $items['R-BERAT']->audit_status);
        $this->assertEquals(15000, $items['R-BERAT']->discrepancy_amount);

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
                // Pilihan kota asal diambil dari Tarif Kontrak ekspedisi itu (seeder J&T: CIREBON & BANDUNG)
                'origin_city' => 'CIREBON',
                'invoice_file' => UploadedFile::fake()->create('PT. Satu Media Indonesia September 2026 - 21 Express.xlsx', 50, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Nomor invoice kosong: diambil dari nama file
        $invoice = FreightInvoice::sole();
        $this->assertSame('PT. Satu Media Indonesia September 2026 - 21 Express', $invoice->invoice_number);
        $this->assertSame('CIREBON', $invoice->origin_city);
        $this->assertSame(now()->toDateString(), $invoice->invoice_date->toDateString());
        Queue::assertPushed(ProcessFreightReconciliationJob::class);
    }

    public function test_admin_approves_a_special_agreement_manually(): void
    {
        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-CHARTER',
            'invoice_date' => now(),
            'status' => 'processing',
        ]);
        $rows = [[
            'no_resi_awb' => '100239850235', 'no_sj' => '', 'origin_depo' => '', 'destination_city' => 'CIREBON',
            'billed_weight' => 4285.0, 'billed_rate' => 2900.0, 'billed_insurance' => 0.0, 'billed_total' => 12426500.0,
        ]];
        $service = new FreightReconciliationService;
        $item = $service->reconcile($invoice, $rows)->items->sole();
        $this->assertEquals(12426500, $invoice->fresh()->total_unverified_amount);

        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'Update:FreightInvoice', 'guard_name' => 'web']));
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        // Charter disepakati Rp2,5 jt
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewFreightInvoice::class])
            ->callTableAction('approveManually', $item, data: ['manual_approved_amount' => 2500000, 'manual_note' => 'Charter 2,5 jt'])
            ->assertHasNoTableActionErrors()
            ->assertSee('DISETUJUI MANUAL');

        $invoice->refresh();
        $this->assertEquals(2500000, $invoice->total_approved_amount);
        $this->assertEquals(9926500, $invoice->total_discrepancy_amount);
        $this->assertEquals(0, $invoice->total_unverified_amount);
        $this->assertSame(1, $invoice->matched_count);
        $this->assertSame($admin->id, $item->fresh()->manual_approved_by);

        // Audit ulang tidak menghapus persetujuan manual
        $service->reconcile($invoice, $rows);
        $this->assertEquals(2500000, $invoice->fresh()->total_approved_amount);
        $this->assertSame('Charter 2,5 jt', $invoice->items()->sole()->manual_note);

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewFreightInvoice::class])
            ->callTableAction('cancelManualApproval', $invoice->items()->sole());

        $this->assertEquals(12426500, $invoice->fresh()->total_unverified_amount);
        $this->assertEquals(0, $invoice->fresh()->total_approved_amount);
    }

    public function test_reaudit_asks_for_the_origin_city(): void
    {
        Queue::fake();
        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:FreightInvoice', 'guard_name' => 'web']));
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        // Invoice lama tanpa kota asal: semua tarif tidak bisa dicari
        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-TANPA-ASAL',
            'invoice_date' => now(),
            'status' => 'audited',
        ]);

        Livewire::test(ListFreightInvoices::class)
            ->callAction(TestAction::make('reAudit')->table($invoice), data: ['origin_city' => 'BANDUNG'])
            ->assertHasNoFormErrors()
            ->assertNotified('Audit Ulang Dimulai');

        $this->assertSame('BANDUNG', $invoice->fresh()->origin_city);
        Queue::assertPushed(ProcessFreightReconciliationJob::class, fn (ProcessFreightReconciliationJob $job): bool => $job->invoice->is($invoice));
    }

    public function test_detail_page_shows_the_invoice_header_and_only_the_resi_table(): void
    {
        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        foreach (['ViewAny:FreightInvoice', 'View:FreightInvoice'] as $permission) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-21-SEP',
            'invoice_date' => '2026-10-19',
            'origin_city' => 'JAKARTA',
            'status' => 'audited',
            'total_items_count' => 173,
            'matched_count' => 164,
            'discrepancy_count' => 3,
            'unrecognized_count' => 6,
            'total_billed_amount' => 42143250,
            'total_approved_amount' => 39996250,
            'total_discrepancy_amount' => 1881000,
            'total_unverified_amount' => 266000,
        ]);

        Livewire::test(ViewFreightInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSee(['INV-21-SEP', $this->expedition->name, 'asal JAKARTA', '19/10/2026', 'Selesai Diaudit'])
            // Total tagihan cukup di daftar invoice dan Aksi › Penjelasan Angka
            ->assertDontSee(['Informasi Invoice', 'Ringkasan Tagihan', 'Total Ditagih', '164 resi sesuai Tarif Kontrak'])
            ->assertSeeLivewire(ItemsRelationManager::class);
    }

    public function test_explanation_menu_breaks_down_the_totals(): void
    {
        $role = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'ViewAny:FreightInvoice', 'guard_name' => 'web']));
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-PENJELASAN',
            'invoice_date' => now(),
            'status' => 'processing',
        ]);
        (new FreightReconciliationService)->reconcile($invoice, [[
            'no_resi_awb' => 'CHARTER-1', 'no_sj' => '', 'origin_depo' => '', 'destination_city' => 'CIREBON',
            'billed_weight' => 4285.0, 'billed_rate' => 2900.0, 'billed_insurance' => 0.0, 'billed_total' => 12426500.0,
        ]]);

        Livewire::test(ListFreightInvoices::class)
            ->mountAction(TestAction::make('explainTotals')->table($invoice))
            ->assertMountedActionModalSee(['Ditagih = Disetujui + Koreksi + Belum Terverifikasi', 'TARIF BELUM ADA', 'Rp 12.426.500', 'Tambah tarif / setujui manual']);
    }

    public function test_each_line_that_is_not_paid_in_full_shows_a_short_reason(): void
    {
        $item = fn (array $attributes): FreightInvoiceItem => new FreightInvoiceItem($attributes + ['billed_insurance' => 0]);

        // ECO Jakarta → Palembang belum ada tarifnya; DARAT minimal 10 kg × Rp 3.000 = Rp 30.000
        $this->assertSame('Harusnya DARAT: 10 kg × Rp 3.000', $item(['audit_status' => 'revisi_darat', 'billed_rate' => 31000, 'agreed_rate' => 3000, 'billed_total' => 62000, 'expected_total' => 30000])->reason());
        $this->assertSame('Tarif lebih mahal Rp 500/kg dari kontrak', $item(['audit_status' => 'discrepancy_rate', 'billed_rate' => 2000, 'agreed_rate' => 1500, 'billed_total' => 20000, 'expected_total' => 15000])->reason());
        $this->assertSame('Berat harusnya 10 kg', $item(['audit_status' => 'discrepancy_weight', 'billed_rate' => 1500, 'agreed_rate' => 1500, 'billed_total' => 27000, 'expected_total' => 15000])->reason());
        $this->assertSame('Belum terverifikasi: tarif rute belum ada', $item(['audit_status' => 'missing_rate', 'billed_total' => 48000, 'expected_total' => 0])->reason());
        $this->assertSame('Disetujui manual: Charter 2,5 jt', $item(['audit_status' => 'missing_rate', 'manual_approved_at' => now(), 'manual_note' => 'Charter 2,5 jt'])->reason());
        $this->assertNull($item(['audit_status' => 'matched', 'billed_rate' => 1500, 'agreed_rate' => 1500, 'billed_total' => 15000, 'expected_total' => 15000])->reason());
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

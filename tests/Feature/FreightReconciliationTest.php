<?php

namespace Tests\Feature;

use App\Filament\Resources\FreightInvoices\Actions\ExplainTotalsAction;
use App\Filament\Resources\FreightInvoices\Pages\CreateFreightInvoice;
use App\Filament\Resources\FreightInvoices\Pages\ListFreightInvoices;
use App\Filament\Resources\FreightInvoices\Pages\ViewFreightInvoice;
use App\Filament\Resources\FreightInvoices\RelationManagers\ItemsRelationManager;
use App\Jobs\ProcessFreightReconciliationJob;
use App\Models\CsaShipment;
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

    public function test_each_line_shows_a_short_reason(): void
    {
        $item = fn (array $attributes): FreightInvoiceItem => new FreightInvoiceItem($attributes + ['billed_insurance' => 0]);

        // ECO Jakarta → Palembang belum ada tarifnya; DARAT minimal 10 kg × Rp 3.000 = Rp 30.000
        $this->assertSame('Harusnya DARAT: 10 kg × Rp 3.000', $item(['audit_status' => 'revisi_darat', 'billed_rate' => 31000, 'agreed_rate' => 3000, 'billed_total' => 62000, 'expected_total' => 30000])->reason());
        $this->assertSame('Tarif lebih mahal Rp 500/kg dari kontrak', $item(['audit_status' => 'discrepancy_rate', 'billed_rate' => 2000, 'agreed_rate' => 1500, 'billed_total' => 20000, 'expected_total' => 15000])->reason());
        $this->assertSame('Berat harusnya 10 kg', $item(['audit_status' => 'discrepancy_weight', 'billed_rate' => 1500, 'agreed_rate' => 1500, 'billed_total' => 27000, 'expected_total' => 15000])->reason());
        $this->assertSame('Belum terverifikasi: tarif rute belum ada', $item(['audit_status' => 'missing_rate', 'billed_total' => 48000, 'expected_total' => 0])->reason());
        $this->assertSame('Disetujui manual: Charter 2,5 jt', $item(['audit_status' => 'missing_rate', 'manual_approved_at' => now(), 'manual_note' => 'Charter 2,5 jt'])->reason());

        // Yang sesuai juga diberi keterangan
        $this->assertSame('Sesuai kontrak: 14 kg × Rp 1.500', $item(['audit_status' => 'matched', 'billed_weight' => 14, 'billed_rate' => 1500, 'agreed_rate' => 1500, 'billed_total' => 21000, 'expected_total' => 21000])->reason());
        $this->assertSame('Sesuai: lebih murah dari kontrak Rp 8.000/kg', $item(['audit_status' => 'matched', 'billed_weight' => 1, 'billed_rate' => 7000, 'agreed_rate' => 8000, 'billed_total' => 7000, 'expected_total' => 7000])->reason());
        $this->assertSame('Sesuai: tidak lebih mahal dari tarif DARAT', $item(['audit_status' => 'matched', 'billed_weight' => 10, 'billed_rate' => 2500, 'agreed_rate' => 3000, 'billed_total' => 25000, 'expected_total' => 25000, 'audit_notes' => 'Tarif ECO JAKARTA → PALEMBANG belum ada di Tarif Kontrak; tagihan tidak lebih mahal dari DARAT'])->reason());
    }

    public function test_ujung_pandang_on_the_invoice_uses_the_makassar_contract_rate(): void
    {
        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-UPG-001',
            'invoice_date' => now(),
            'origin_city' => 'JAKARTA',
            'status' => 'processing',
        ]);
        ExpeditionRateCard::create([
            'expedition_id' => $this->expedition->id,
            'origin_depo' => 'JAKARTA',
            'destination_city' => 'MAKASSAR',
            'destination_district' => 'MAKASSAR',
            'service_type' => 'DARAT',
            'rate_per_kg' => 7000,
            'min_kg' => 50,
            'is_active' => true,
        ]);

        $result = (new FreightReconciliationService)->reconcile($invoice, [[
            'no_resi_awb' => '100239896516',
            'destination_city' => 'UJUNG PANDANG',
            'service_type' => 'DARAT',
            'billed_weight' => 30.0,
            'billed_rate' => 7000.0,
            'billed_total' => 210000.0,
        ]]);

        $item = $result->items->sole();
        $this->assertSame('matched', $item->audit_status);
        $this->assertEquals(7000, $item->agreed_rate);
        $this->assertSame('Sesuai kontrak: 30 kg × Rp 7.000', $item->reason());
    }

    public function test_duplicate_kota_columns_and_koli_fallback_are_parsed_correctly(): void
    {
        $path = $this->invoiceFile([
            ['TANGGAL', 'NO. RESI', 'PENERIMA', 'KOTA', 'PENGIRIM', 'KOTA', 'KOLI', 'BIAYA'],
            ['2026-09-02', '880', 'RASYA', 'PARAKAN', 'MEDIA SELULAR INDONESIA', 'SEMARANG', 1, 50000],
            ['2026-09-05', '885', 'CENTRA NUSA', 'SALATIGA', 'CV. TOP', 'SEMARANG', 2, 100000],
        ]);

        try {
            $rows = (new FreightReconciliationService)->parseInvoiceFile($path);

            $this->assertCount(2, $rows);
            $this->assertSame('SEMARANG', $rows[0]['origin_depo']);
            $this->assertSame('PARAKAN', $rows[0]['destination_city']);
            $this->assertSame(1.0, $rows[0]['billed_weight']);
            $this->assertSame(50000.0, $rows[0]['billed_total']);

            $this->assertSame('SEMARANG', $rows[1]['origin_depo']);
            $this->assertSame('SALATIGA', $rows[1]['destination_city']);
            $this->assertSame(2.0, $rows[1]['billed_weight']);
            $this->assertSame(100000.0, $rows[1]['billed_total']);
        } finally {
            @unlink($path);
        }
    }

    public function test_jnt_waybill_format_and_insurance_reason_are_parsed_and_matched(): void
    {
        ExpeditionRateCard::create([
            'expedition_id' => $this->expedition->id,
            'origin_depo' => 'CIREBON',
            'destination_city' => 'GARAWANGI',
            'service_type' => 'DARAT',
            'rate_per_kg' => 3000,
            'min_kg' => 1,
            'is_active' => true,
        ]);

        $path = $this->invoiceFile([
            ['STATEMENT PP PM REG CV.TOP SELULAR'],
            ['PERIODE OKTOBER 2026'],
            ['No', 'Tanggal Pengiriman', 'No. Waybill', 'Tempat Tujuan', 'Berat ', 'Biaya Asuransi', 'Total Biaya', 'Total Tagihan', 'Layanan', 'Nomor SJ'],
            [1, '2026-10-02', '1364424429', 'GARAWANGI', 3, 21056, 51056, 30056, 'EZ', '2610000063'],
        ]);

        try {
            $service = new FreightReconciliationService;
            $rows = $service->parseInvoiceFile($path);
            $this->assertCount(1, $rows);
            $this->assertSame('1364424429', $rows[0]['no_resi_awb']);

            $invoice = FreightInvoice::create([
                'expedition_id' => $this->expedition->id,
                'invoice_number' => 'INV-JNT-TEST',
                'invoice_date' => now(),
                'origin_city' => 'CIREBON',
                'status' => 'processing',
            ]);

            $result = $service->reconcile($invoice, $rows);
            $item = $result->items->sole();

            $this->assertSame('matched', $item->audit_status);
            $this->assertEquals(3000, $item->billed_rate);
            $this->assertSame('Sesuai kontrak: 3 kg × Rp 3.000 + asuransi Rp 21.056', $item->reason());
        } finally {
            @unlink($path);
        }
    }

    public function test_reconciliation_enriches_items_from_data_pengiriman_by_resi_and_checks_warehouse_weight(): void
    {
        // Dua SJ digabung dalam 1 resi (plus 1 baris duplikat identik yang tidak boleh terhitung ganda)
        $s1 = CsaShipment::create([
            'source' => CsaShipment::SOURCE_SPREADSHEET,
            'kode_gudang' => 'GMCRB',
            'nama_gudang' => 'GUDANG CIREBON',
            'no_sj' => '2610000406',
            'no_resi_awb' => '1364514632',
            'badan_usaha' => 'CV. TOP SELULAR',
            'reff_note' => 'REALME - DP CIREBON',
            'brand' => 'REALME',
            'total_nominal_sj' => 10000000,
            'berat' => 2.0,
            'nama_ekspedisi' => 'J&T EXPRESS',
            'target_sheet' => 'CIREBON',
        ]);
        CsaShipment::create([
            'source' => CsaShipment::SOURCE_SPREADSHEET,
            'kode_gudang' => 'GMCRB',
            'nama_gudang' => 'GUDANG CIREBON',
            'no_sj' => '2610000406',
            'no_resi_awb' => '1364514632',
            'badan_usaha' => 'CV. TOP SELULAR',
            'reff_note' => 'REALME - DP CIREBON',
            'brand' => 'REALME',
            'total_nominal_sj' => 10000000,
            'berat' => 2.0,
            'nama_ekspedisi' => 'J&T EXPRESS',
            'target_sheet' => 'CIREBON',
        ]);
        CsaShipment::create([
            'source' => CsaShipment::SOURCE_SPREADSHEET,
            'kode_gudang' => 'GMCRB',
            'nama_gudang' => 'GUDANG CIREBON',
            'no_sj' => '2610000450',
            'no_resi_awb' => '1364514632',
            'badan_usaha' => 'CV. TOP SELULAR',
            'reff_note' => 'REALME - DP CIREBON',
            'brand' => 'REALME',
            'total_nominal_sj' => 12723000,
            'berat' => 3.0,
            'nama_ekspedisi' => 'J&T EXPRESS',
            'target_sheet' => 'CIREBON',
        ]);

        // Satu resi dengan selisih berat (gudang 2 kg, ditagih ekspedisi 5 kg)
        CsaShipment::create([
            'source' => CsaShipment::SOURCE_SPREADSHEET,
            'kode_gudang' => 'GMCRB',
            'nama_gudang' => 'GUDANG CIREBON',
            'no_sj' => '2610000999',
            'no_resi_awb' => '1364999999',
            'badan_usaha' => 'PT. MEDIA SELULAR INDONESIA',
            'reff_note' => 'IT - CIREBON',
            'brand' => 'ITEL',
            'total_nominal_sj' => 8000000,
            'berat' => 2.0,
            'nama_ekspedisi' => 'J&T',
            'target_sheet' => 'CIREBON',
        ]);

        // Resi placeholder '-' di Data Pengiriman tidak boleh ikut cocok
        CsaShipment::create([
            'source' => CsaShipment::SOURCE_SPREADSHEET,
            'kode_gudang' => 'GMCRB',
            'nama_gudang' => 'GUDANG CIREBON',
            'no_sj' => '2610008888',
            'no_resi_awb' => '-',
            'badan_usaha' => 'SHOULD NOT MATCH',
            'target_sheet' => 'CIREBON',
        ]);

        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-SHIP-001',
            'invoice_date' => now(),
            'origin_city' => 'CIREBON',
            'status' => 'processing',
        ]);

        $result = (new FreightReconciliationService)->reconcile($invoice, [
            [
                'no_resi_awb' => '1364514632',
                'destination_city' => 'INDRAMAYU',
                'service_type' => 'EZ',
                'billed_weight' => 5.0,
                'billed_rate' => 8000.0,
                'billed_total' => 40000.0,
            ],
            [
                'no_resi_awb' => '1364999999',
                'destination_city' => 'INDRAMAYU',
                'service_type' => 'EZ',
                'billed_weight' => 5.0,
                'billed_rate' => 8000.0,
                'billed_total' => 40000.0,
            ],
            [
                'no_resi_awb' => '-',
                'destination_city' => 'INDRAMAYU',
                'service_type' => 'EZ',
                'billed_weight' => 1.0,
                'billed_rate' => 8000.0,
                'billed_total' => 8000.0,
            ],
        ]);

        $items = $result->items->keyBy('no_resi_awb');

        $first = $items->get('1364514632');
        $this->assertSame($s1->id, $first->csa_shipment_id);
        $this->assertSame('matched', $first->shipment_match_status);
        $this->assertSame('resi', $first->shipment_matched_by);
        $this->assertSame('2610000406, 2610000450', $first->no_sj);
        $this->assertSame('CV. TOP SELULAR', $first->shipment_badan_usaha);
        $this->assertSame('REALME - DP CIREBON', $first->shipment_reff_note);
        $this->assertSame('REALME', $first->shipment_brand);
        $this->assertEquals(22723000.0, (float) $first->shipment_nominal_sj);
        $this->assertEquals(5.0, (float) $first->shipment_weight);

        $second = $items->get('1364999999');
        $this->assertSame('weight_mismatch', $second->shipment_match_status);
        $this->assertEquals(2.0, (float) $second->shipment_weight);
        $this->assertStringContainsString('Berat vs Data Pengiriman', $second->audit_notes);

        $placeholder = $items->get('-');
        $this->assertNull($placeholder->csa_shipment_id);
        $this->assertSame('unmatched', $placeholder->shipment_match_status);

        $summary = ExplainTotalsAction::shipmentSummary($result);
        $this->assertSame(['matched' => 1, 'weight_mismatch' => 1, 'expedition_mismatch' => 0, 'unmatched' => 1], $summary);

        $breakdown = ExplainTotalsAction::companyBreakdown($result);
        $this->assertCount(3, $breakdown);
    }

    public function test_sj_fallback_does_not_link_wrong_depo_when_sj_number_collides(): void
    {
        // Nomor SJ sama dipakai di depo BANDUNG (bukan CIREBON)
        CsaShipment::create([
            'source' => CsaShipment::SOURCE_CSA,
            'kode_gudang' => 'GMBDG',
            'nama_gudang' => 'GUDANG BANDUNG',
            'no_sj' => '2610000063',
            'no_resi_awb' => null,
            'badan_usaha' => 'PT Media Selular Indonesia',
            'reff_note' => 'IT-BANDUNG',
            'brand' => 'ITEL',
            'total_nominal_sj' => 4291891,
            'target_sheet' => 'BANDUNG',
        ]);

        // Nomor SJ sama di depo CIREBON (yang benar)
        $cirebon = CsaShipment::create([
            'source' => CsaShipment::SOURCE_CSA,
            'kode_gudang' => 'GMCRB',
            'nama_gudang' => 'GUDANG CIREBON',
            'no_sj' => '2610000063',
            'no_resi_awb' => null,
            'badan_usaha' => 'CV. TOP SELULAR',
            'reff_note' => 'REALME - DP CIREBON',
            'brand' => 'REALME',
            'total_nominal_sj' => 11686000,
            'berat' => 3.0,
            'target_sheet' => 'CIREBON',
        ]);

        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-SJ-DISAMBIGUATE',
            'invoice_date' => now(),
            'origin_city' => 'CIREBON',
            'status' => 'processing',
        ]);

        $result = (new FreightReconciliationService)->reconcile($invoice, [[
            'no_resi_awb' => '1364424429',
            'no_sj' => '2610000063',
            'destination_city' => 'INDRAMAYU',
            'billed_weight' => 3.0,
            'billed_rate' => 8000.0,
            'billed_total' => 24000.0,
        ]]);

        $item = $result->items->sole();
        $this->assertSame($cirebon->id, $item->csa_shipment_id);
        $this->assertSame('sj', $item->shipment_matched_by);
        $this->assertSame('CV. TOP SELULAR', $item->shipment_badan_usaha);
        $this->assertEquals(11686000.0, (float) $item->shipment_nominal_sj);
    }

    public function test_detects_expedition_mismatch_when_warehouse_records_different_carrier(): void
    {
        // Di gudang dicatat dikirim via KARYA MANDIRI
        $shipment = CsaShipment::create([
            'source' => CsaShipment::SOURCE_SPREADSHEET,
            'kode_gudang' => 'GMCRB',
            'nama_gudang' => 'GUDANG CIREBON',
            'no_sj' => '2610007777',
            'no_resi_awb' => '9999123456',
            'badan_usaha' => 'CV. TOP SELULAR',
            'reff_note' => 'REALME - DP CIREBON',
            'brand' => 'REALME',
            'total_nominal_sj' => 5000000,
            'berat' => 3.0,
            'nama_ekspedisi' => 'KARYA MANDIRI',
            'target_sheet' => 'CIREBON',
        ]);

        // Ditagih oleh invoice J&T Express
        $invoice = FreightInvoice::create([
            'expedition_id' => $this->expedition->id,
            'invoice_number' => 'INV-JNT-MISMATCH',
            'invoice_date' => now(),
            'origin_city' => 'CIREBON',
            'status' => 'processing',
        ]);

        $result = (new FreightReconciliationService)->reconcile($invoice, [[
            'no_resi_awb' => '9999123456',
            'destination_city' => 'INDRAMAYU',
            'service_type' => 'EZ',
            'billed_weight' => 3.0,
            'billed_rate' => 8000.0,
            'billed_total' => 24000.0,
        ]]);

        $item = $result->items->sole();
        $this->assertSame($shipment->id, $item->csa_shipment_id);
        $this->assertSame('expedition_mismatch', $item->shipment_match_status);
        $this->assertStringContainsString('Beda Ekspedisi', $item->audit_notes);
        $this->assertSame('BEDA EKSPEDISI', $item->shipmentMatchLabel());
        $this->assertSame('danger', $item->shipmentMatchColor());
        $this->assertStringContainsString('Perhatian: Ekspedisi di catatan gudang berbeda', $item->shipmentMatchTooltip());
    }

    public function test_smart_fallback_matches_shipment_via_date_store_and_city_when_resi_and_sj_are_not_available(): void
    {
        $kmExpedition = Expedition::create([
            'name' => 'Karya Mandiri',
            'code' => 'KM',
            'is_active' => true,
        ]);

        ExpeditionRateCard::create([
            'expedition_id' => $kmExpedition->id,
            'origin_depo' => 'SEMARANG',
            'destination_city' => 'PARAKAN',
            'service_type' => 'DARAT',
            'rate_per_kg' => 50000.0,
            'min_kg' => 1.0,
            'is_active' => true,
        ]);

        // Catatan gudang belum diisi nomor resi ekspedisi oleh admin gudang
        $shipment = CsaShipment::create([
            'source' => CsaShipment::SOURCE_SPREADSHEET,
            'kode_gudang' => 'GMSMG',
            'nama_gudang' => 'SEMARANG',
            'no_sj' => '2609000545',
            'no_resi_awb' => '',
            'tujuan_dealer' => 'CV. RASYA ACCESSORIES',
            'nama_kota' => 'PARAKAN',
            'tanggal_kirim' => '2026-09-02',
            'nama_ekspedisi' => 'KARYA MANDIRI',
            'target_sheet' => 'SEMARANG',
            'badan_usaha' => 'PT. MEDIA SELULAR INDONESIA',
            'reff_note' => 'IT-SEMARANG',
            'total_nominal_sj' => 5000000.0,
            'berat' => 1.0,
        ]);

        // Invoice ekspedisi hanya membawa nomor tanda terima ekspedisi (880), tanpa nomor SJ
        $invoice = FreightInvoice::create([
            'expedition_id' => $kmExpedition->id,
            'invoice_number' => 'INV-KM-TEST',
            'invoice_date' => '2026-09-30',
            'origin_city' => 'SEMARANG',
            'status' => 'processing',
        ]);

        $result = (new FreightReconciliationService)->reconcile($invoice, [[
            'no_resi_awb' => '880',
            'no_sj' => '',
            'shipment_date' => '2026-09-02',
            'recipient_name' => 'RASYA',
            'destination_city' => 'PARAKAN',
            'billed_weight' => 1.0,
            'billed_rate' => 50000.0,
            'billed_total' => 50000.0,
        ]]);

        $item = $result->items->sole();

        // 1. Berhasil terhubung ke Data Pengiriman
        $this->assertSame($shipment->id, $item->csa_shipment_id);
        $this->assertSame('matched', $item->shipment_match_status);
        $this->assertSame('dealer_date', $item->shipment_matched_by);

        // 2. Nomor SJ otomatis ditarik dari Data Pengiriman ke item invoice
        $this->assertSame('2609000545', $item->no_sj);
        $this->assertStringContainsString('Cocok via Toko [CV. RASYA ACCESSORIES]', $item->audit_notes);
        $this->assertStringContainsString('Cocok via Tanggal, Toko & Kota', $item->shipmentMatchTooltip());

        // 3. Win-win: Nomor resi ekspedisi otomatis di-backfill ke catatan pengiriman gudang
        $this->assertSame('880', $shipment->fresh()->no_resi_awb);
    }

    public function test_rax_style_deep_header_with_subheaders_and_awb_variations_are_parsed_correctly(): void
    {
        $rows = [
            ['PT. RESTU ABADI EKSPEDISI'],
            ['INVOICE'],
            ['NO. 0000/INV/RAE-BDG/KEU/IX/26'],
            ['Bandung, 30 September 2026'],
            ['Nama Customer', '', '', ': CV. COMPLETE SELULAR'],
            ['Alamat', '', '', ': Jl. Petratean No. 011'],
            ['Kota Cirebon'],
            ['NPWP', '', '', ': 31.282.101.0-426.000'],
            [], [], [], [], [], [], [], [], [], // Empty filler rows up to row 17
            ['No.', 'AWB', 'TGL', 'PENGIRIM', 'KOTA', 'PENERIMA', 'KOTA', 'KOLI', 'BERAT (Kg)', 'HARGA', 'JUMLAH'],
            ['', '', '', '', '', '', '', '', '', '1 Kg', 'Kg Minimum'],
            [1, 'IDY0262121', '2026-09-01', 'CV COMPLETE', 'CIREBON', 'SP JATIBARANG', 'JATIBARANG', 1, 8, 3300, 26400],
            [2, 'IDY0280754', '2026-09-01', 'CV COMPLETE', 'CIREBON', 'UNBOXING GS', 'JATIBARANG', 1, 3, 3300, 9900],
            ['Total', '', '', '', '', '', '', '', '', '', 36300],
            ['Ppn 1,1%', '', '', '', '', '', '', '', '', '', 399.3],
            ['Grand Total', '', '', '', '', '', '', '', '', '', 36699.3],
            ['Terbilang : # Tiga Puluh Enam Ribu #'],
        ];

        $path = $this->invoiceFile($rows);

        try {
            $service = new FreightReconciliationService;
            $items = $service->parseInvoiceFile($path);

            $this->assertCount(2, $items);
            $this->assertSame('IDY0262121', $items[0]['no_resi_awb']);
            $this->assertSame('2026-09-01', $items[0]['shipment_date']);
            $this->assertSame('CIREBON', $items[0]['origin_depo']);
            $this->assertSame('SP JATIBARANG', $items[0]['recipient_name']);
            $this->assertSame('JATIBARANG', $items[0]['destination_city']);
            $this->assertSame(8.0, $items[0]['billed_weight']);
            $this->assertSame(3300.0, $items[0]['billed_rate']);
            $this->assertSame(26400.0, $items[0]['billed_total']);

            $this->assertSame('IDY0280754', $items[1]['no_resi_awb']);
            $this->assertSame(9900.0, $items[1]['billed_total']);
        } finally {
            @unlink($path);
        }
    }

    public function test_rax_kulinari_awwy_abill_and_destination_subheaders_are_parsed(): void
    {
        $rows = [
            ['PT. RESTU ABADI EKSPEDISI'],
            ['INVOICE'],
            [], [], [], [], [], [], [], [], [], [], [], [], [], [], [],
            ['No.', 'AWWY ABILL', 'TANGGAL', 'PENGIRIM', 'TUJUAN', '', 'COLLY', 'BERAT (Kg)', 'HARGA', 'JUMLAH'],
            ['', '', '', '', 'PENERIMA', 'KOTA', '', '', 'PER KILO', ''],
            [1, '956070', '2026-09-04', 'MOMOYO TANGERANG', 'MOMOYO', 'CIREBON', 11, 196, 2000, 392000],
            ['Total', 392000],
        ];

        $path = $this->invoiceFile($rows);

        try {
            $service = new FreightReconciliationService;
            $items = $service->parseInvoiceFile($path);

            $this->assertCount(1, $items);
            $this->assertSame('956070', $items[0]['no_resi_awb']);
            $this->assertSame('2026-09-04', $items[0]['shipment_date']);
            $this->assertSame('MOMOYO', $items[0]['recipient_name']);
            $this->assertSame('CIREBON', $items[0]['destination_city']);
            $this->assertSame(196.0, $items[0]['billed_weight']);
            $this->assertSame(2000.0, $items[0]['billed_rate']);
            $this->assertSame(392000.0, $items[0]['billed_total']);
        } finally {
            @unlink($path);
        }
    }

    public function test_sentral_cargo_prefixed_destination_and_backtick_resi_are_matched_correctly(): void
    {
        $sentral = Expedition::firstOrCreate(
            ['code' => 'SENTRALCAR'],
            ['name' => 'SENTRAL CARGO', 'is_active' => true]
        );

        ExpeditionRateCard::create([
            'expedition_id' => $sentral->id,
            'origin_depo' => 'PURWOKERTO',
            'destination_city' => 'CIREBON',
            'destination_district' => 'CIREBON',
            'service_type' => 'DARAT',
            'rate_per_kg' => 4000,
            'min_kg' => 10,
            'is_active' => true,
        ]);

        $shipment = CsaShipment::create([
            'source' => CsaShipment::SOURCE_SPREADSHEET,
            'kode_gudang' => 'PWK',
            'nama_gudang' => 'PURWOKERTO',
            'target_sheet' => 'PURWOKERTO',
            'no_sj' => '2610000010',
            'no_resi_awb' => '`00740201326', // Stored with Excel backtick prefix in warehouse
            'nama_kota' => 'CIREBON',
            'tanggal_kirim' => '2026-10-09',
            'badan_usaha' => 'CV. TOP SELULAR',
            'tujuan_dealer' => 'CV TOP SELULAR CIREBON',
            'nama_ekspedisi' => 'SENTRAL CARGO',
            'berat' => 25.0,
            'qty_koli' => 5,
        ]);

        $invoice = FreightInvoice::create([
            'expedition_id' => $sentral->id,
            'invoice_number' => 'INV-SENTRAL-TEST',
            'invoice_date' => '2026-10-31',
            'origin_city' => 'PURWOKERTO',
            'status' => 'processing',
        ]);

        $service = new FreightReconciliationService;
        $result = $service->reconcile($invoice, [[
            'no_resi_awb' => '00740201326', // Clean resi from invoice
            'no_sj' => '',
            'shipment_date' => '2026-10-16',
            'origin_depo' => 'Purwokerto',
            'destination_city' => 'D-Pekalipan, Cirebon',
            'recipient_name' => 'CV TOP CIREBON',
            'billed_weight' => 25.0,
            'billed_rate' => 4000.0,
            'billed_insurance' => 196300.0,
            'billed_total' => 296300.0,
        ]]);

        $item = $result->items->sole();

        // 1. Tarif cocok ke CIREBON
        $this->assertSame('matched', $item->audit_status);
        $this->assertSame(4000.0, (float) $item->agreed_rate);
        $this->assertSame(0.0, (float) $item->discrepancy_amount);

        // 2. Terhubung ke data pengiriman gudang meskipun di database ada awalan backtick
        $this->assertSame($shipment->id, $item->csa_shipment_id);
        $this->assertSame('matched', $item->shipment_match_status);
        $this->assertSame('2610000010', $item->no_sj);
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

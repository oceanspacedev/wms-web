<?php

namespace Tests\Feature;

use App\Filament\Resources\CsaShipments\Pages\ListCsaShipments;
use App\Filament\Resources\TrackingOrders\Pages\CreateTrackingOrder;
use App\Filament\Resources\TrackingOrders\Pages\ListTrackingOrders;
use App\Jobs\ProcessCsaImportJob;
use App\Models\CsaImport;
use App\Models\CsaShipment;
use App\Models\Depo;
use App\Models\TrackingOrder;
use App\Models\User;
use App\Models\WarehouseMapping;
use App\Services\CsaExcelParserService;
use App\Services\TrackingOrderExporter;
use Database\Seeders\TrackingOrderSeeder;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrackingOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_tracking_orders_index(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin/tracking-orders');
        $response->assertStatus(200);
    }

    public function test_tracking_orders_can_be_seeded_and_queried(): void
    {
        $this->seed();
        $this->seed(TrackingOrderSeeder::class);

        $this->assertDatabaseHas('tracking_orders', [
            'no_sj' => '2401304519',
            'nama_dealer' => 'PT BCA',
            'nama_pengirim' => 'Heidy',
            'nama_penerima' => 'Imas',
        ]);

        $order = TrackingOrder::where('no_sj', '2401304519')->firstOrFail();
        $this->assertStringContainsString('Bandung City', $order->address);
    }

    public function test_super_admin_can_access_create_and_view_tracking_order(): void
    {
        $this->seed();
        $this->seed(TrackingOrderSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $order = TrackingOrder::firstOrFail();

        $createResponse = $this->actingAs($admin)->get('/admin/tracking-orders/create');
        $createResponse->assertStatus(200);

        $viewResponse = $this->actingAs($admin)->get("/admin/tracking-orders/{$order->id}");
        $viewResponse->assertStatus(200);
    }

    public function test_panel_user_only_sees_their_own_tracking_orders_like_the_mobile_app(): void
    {
        $courier = $this->courierWithPanelAccess();
        $ownOrder = TrackingOrder::create(['no_sj' => 'SJ-SENDIRI', 'nama_dealer' => 'Toko', 'user_id' => $courier->id]);
        $otherOrder = TrackingOrder::create(['no_sj' => 'SJ-LAIN', 'nama_dealer' => 'Toko', 'user_id' => User::factory()->create()->id]);

        $this->actingAs($courier);

        Livewire::test(ListTrackingOrders::class)
            ->assertCanSeeTableRecords([$ownOrder])
            ->assertCanNotSeeTableRecords([$otherOrder]);

        $this->get("/admin/tracking-orders/{$ownOrder->id}")->assertOk();
        $this->get("/admin/tracking-orders/{$otherOrder->id}")->assertNotFound();
    }

    public function test_tracking_order_created_from_the_panel_belongs_to_the_logged_in_user(): void
    {
        $courier = $this->courierWithPanelAccess();
        $this->actingAs($courier);

        Livewire::test(CreateTrackingOrder::class)
            ->fillForm(['no_sj' => 'SJ-WEB-001', 'nama_dealer' => 'Toko Web'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($courier->id, TrackingOrder::where('no_sj', 'SJ-WEB-001')->firstOrFail()->user_id);
    }

    public function test_export_of_the_filtered_period_shows_the_photos_in_excel(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->putFileAs('tracking-orders/nota', UploadedFile::fake()->image('nota.jpg', 1200, 900), 'sj-okt.jpg');

        $courier = $this->courierWithPanelAccess();
        $bandung = Depo::factory()->create(['kode' => 'GMBDG', 'nama' => 'BANDUNG']);
        $courier->update(['depo_id' => $bandung->id]);
        $october = TrackingOrder::create([
            'no_sj' => '2610000123', 'nama_dealer' => 'Toko Oktober', 'jumlah_value_nota' => 1500000,
            'tanggal_pengiriman' => '2026-10-15', 'nama_pengirim' => 'Andi', 'nama_penerima' => 'Imas', 'user_id' => $courier->id,
            'foto_nota_sj' => 'tracking-orders/nota/sj-okt.jpg', 'foto_penerima' => 'tracking-orders/penerima/hilang.jpg',
        ]);
        // Foto dari iPhone (HEIC) tidak bisa dibuka; SJ tanpa foto penerima
        Storage::disk('public')->put('tracking-orders/nota/iphone.heic', 'bukan jpeg');
        TrackingOrder::create(['no_sj' => '2610000122', 'nama_dealer' => 'Toko Kedua', 'tanggal_pengiriman' => '2026-10-14', 'user_id' => $courier->id, 'foto_nota_sj' => 'tracking-orders/nota/iphone.heic']);
        TrackingOrder::create(['no_sj' => 'SJ-SEP', 'nama_dealer' => 'Toko September', 'tanggal_pengiriman' => '2026-09-20', 'user_id' => $courier->id]);
        // SJ kurir lain tidak ikut, sama seperti di tabel
        TrackingOrder::create(['no_sj' => 'SJ-LAIN', 'nama_dealer' => 'Toko', 'tanggal_pengiriman' => '2026-10-16', 'user_id' => User::factory()->create()->id]);

        $this->actingAs($courier);

        Livewire::test(ListTrackingOrders::class)
            ->filterTable('periode', ['dari' => '2026-10-01', 'sampai' => '2026-10-31'])
            ->filterTable('depo', $bandung->id)
            ->assertCanSeeTableRecords([$october])
            ->assertSee('BANDUNG')
            ->callAction('export')
            ->assertNotified('Export dimulai');

        // Antrean di test berjalan langsung: file tersimpan di disk privat & notifikasi berisi tombol Download
        $export = Export::sole();
        $this->assertNotNull($export->completed_at);
        $this->assertSame(2, $export->successful_rows);
        $file = $export->getFileDirectory().'/'.$export->file_name.'.xlsx';
        Storage::disk('local')->assertExists($file);

        $sheet = IOFactory::load(Storage::disk('local')->path($file))->getActiveSheet();
        $this->assertSame(TrackingOrderExporter::HEADERS, $sheet->rangeToArray('A1:Q1')[0]);
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame('2610000123', $sheet->getCell('B2')->getValue());
        $this->assertEquals(1500000, $sheet->getCell('E2')->getValue());
        // Depo otomatis dari akun kurir
        $this->assertSame('BANDUNG', $sheet->getCell('H2')->getValue());
        $this->assertSame('Imas', $sheet->getCell('I2')->getValue());
        // Foto nota tertempel di kolom FOTO NOTA; foto penerima yang filenya hilang diberi keterangan
        $this->assertSame(['J2'], array_map(fn ($drawing): string => $drawing->getCoordinates(), iterator_to_array($sheet->getDrawingCollection())));
        $this->assertSame('Foto tidak ditemukan', $sheet->getCell('K2')->getValue());
        $this->assertSame('Format foto tidak didukung', $sheet->getCell('J3')->getValue());
        $this->assertSame('-', $sheet->getCell('K3')->getValue());
        $this->assertStringEndsWith("/admin/tracking-orders/{$october->id}", $sheet->getCell('Q2')->getHyperlink()->getUrl());

        $notification = $courier->notifications()->sole();
        $this->assertSame('Export Surat Jalan selesai', $notification->data['title']);
        $this->get($notification->data['actions'][0]['url'])->assertOk()->assertDownload($export->file_name.'.xlsx');
    }

    public function test_tracking_order_auto_syncs_to_data_pengiriman_and_merges_with_csa_upload_without_duplicates(): void
    {
        $bandung = Depo::factory()->create(['kode' => 'GMBDG', 'nama' => 'BANDUNG']);
        WarehouseMapping::create([
            'csa_code' => 'GMBDG',
            'csa_name' => 'GUDANG MSIS BANDUNG',
            'target_sheet' => 'BANDUNG',
            'region' => 'JAWA BARAT',
            'is_active' => true,
        ]);

        $courier = User::factory()->create(['depo_id' => $bandung->id]);

        // 1. Surat Jalan dicatat lebih dulu sebelum data CSA di-upload
        TrackingOrder::create([
            'user_id' => $courier->id,
            'no_sj' => '2611009999',
            'nama_dealer' => 'TOKO MAJU JAYA',
            'alamat_dealer' => 'Jl. Soekarno Hatta No. 12',
            'address' => 'Jl. Soekarno Hatta No. 12, Bandung City, West Java',
            'jumlah_value_nota' => 4500000,
            'tanggal_nota' => '2026-11-20',
            'tanggal_pengiriman' => '2026-11-21',
            'nama_pengirim' => 'Andi',
            'nama_penerima' => 'Budi',
            'notes' => 'Titip di satpam',
        ]);

        $shipment = CsaShipment::where('no_sj', '2611009999')->sole();
        $this->assertSame(CsaShipment::SOURCE_SURAT_JALAN, $shipment->source);
        $this->assertSame('GMBDG', $shipment->kode_gudang);
        $this->assertSame('BANDUNG', $shipment->target_sheet);
        $this->assertSame('TOKO MAJU JAYA', $shipment->tujuan_dealer);
        $this->assertSame('Jl. Soekarno Hatta No. 12, Bandung City, West Java', $shipment->alamat_kirim);
        $this->assertSame('BANDUNG', $shipment->nama_kota);
        $this->assertSame('INTERNAL', $shipment->nama_ekspedisi);
        $this->assertEquals(4500000, (float) $shipment->total_nominal_sj);
        $this->assertNull($shipment->status_pengiriman);
        $this->assertSame('2026-11-21', $shipment->tanggal_diterima?->toDateString());

        // 2. Nanti laporan CSA berisi No SJ yang sama di-upload -> digabung ke baris tersebut, tanpa duplikat
        $import = CsaImport::create([
            'file_name' => 'csa-nov.xlsx',
            'file_path' => 'csa-imports/csa-nov.xlsx',
            'status' => 'pending',
        ]);

        $this->mock(CsaExcelParserService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('parseAndAggregate')->once()->andReturn([
                'report_type' => CsaImport::REPORT_SALES,
                'period' => ['2026-11-20', '2026-11-20'],
                'total_raw_rows' => 1,
                'total_shipments' => 1,
                'by_sheet' => ['BANDUNG' => 1],
                'items' => [],
                'shipments' => [[
                    'no_sj' => '2611009999',
                    'no_trans' => 'TR-99',
                    'no_so' => 'SO-99',
                    'tanggal_order' => '2026-11-20',
                    'tanggal_kirim' => '2026-11-20',
                    'badan_usaha' => 'PT. MEDIA SELULAR INDONESIA',
                    'kode_gudang' => 'GMBDG',
                    'nama_gudang' => 'GUDANG MSIS BANDUNG',
                    'target_sheet' => 'BANDUNG',
                    'tujuan_dealer' => 'TOKO MAJU JAYA RESMI',
                    'alamat_kirim' => null,
                    'nama_kota' => null,
                    'brand' => 'TECNO',
                    'reff_note' => 'SO-99',
                    'total_nominal_sj' => 4500000,
                    'qty_unit' => 5,
                    'qty_koli' => 0,
                    'berat' => 0,
                    'ketentuan_biaya_kirim' => null,
                    'nama_ekspedisi' => null,
                    'no_resi_awb' => null,
                    'biaya_kirim' => null,
                    'status_pembayaran' => null,
                    'status_pengiriman' => null,
                    'tanggal_diterima' => null,
                    'ket_isi_unit' => '5 UNIT',
                ]],
            ]);
        });

        (new ProcessCsaImportJob($import))->handle(app(CsaExcelParserService::class));

        $this->assertSame(1, CsaShipment::where('no_sj', '2611009999')->count());
        $shipment->refresh();
        $this->assertSame(CsaShipment::SOURCE_CSA, $shipment->source);
        $this->assertSame('TOKO MAJU JAYA RESMI', $shipment->tujuan_dealer);
        $this->assertSame('TECNO', $shipment->brand);
        // Alamat, kota & status penerimaan dari Surat Jalan tetap dipertahankan saat CSA kosong
        $this->assertSame('Jl. Soekarno Hatta No. 12, Bandung City, West Java', $shipment->alamat_kirim);
        $this->assertSame('BANDUNG', $shipment->nama_kota);
        $this->assertSame('INTERNAL', $shipment->nama_ekspedisi);
        $this->assertNull($shipment->status_pengiriman);
        $this->assertSame('2026-11-21', $shipment->tanggal_diterima?->toDateString());
    }

    public function test_manual_sync_action_backfills_existing_surat_jalan_without_overwriting_csa_fields(): void
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        foreach (['ViewAny:CsaShipment', 'Create:CsaShipment', 'Update:CsaShipment', 'ViewAny:TrackingOrder'] as $perm) {
            $role->givePermissionTo(Permission::findOrCreate($perm, 'web'));
        }
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin);

        // Baris CSA sudah ada dengan alamat & kota kosong, serta nama_ekspedisi sudah terisi admin
        $existingCsa = CsaShipment::create([
            'source' => CsaShipment::SOURCE_CSA,
            'no_sj' => 'SJ-EXIST-01',
            'kode_gudang' => 'GMCRB',
            'target_sheet' => 'CIREBON',
            'tujuan_dealer' => 'DEALER RESMI CSA',
            'alamat_kirim' => null,
            'nama_kota' => null,
            'nama_ekspedisi' => 'RAX',
            'tanggal_kirim' => '2026-11-22',
        ]);

        // Simulasikan data Surat Jalan lama (before auto-sync) via withoutEvents
        TrackingOrder::withoutEvents(function () use ($admin): void {
            TrackingOrder::create([
                'user_id' => $admin->id,
                'no_sj' => 'SJ-EXIST-01',
                'nama_dealer' => 'NAMA BEDA DI SJ',
                'alamat_dealer' => 'Plumbon',
                'address' => 'Kisabalanang, Marikangen, Kec. Plumbon, Kabupaten Cirebon',
                'tanggal_pengiriman' => '2026-11-23',
                'nama_penerima' => 'Sari',
            ]);

            TrackingOrder::create([
                'user_id' => $admin->id,
                'no_sj' => 'SJ-BELUM-CSA',
                'nama_dealer' => 'TOKO BARU',
                'address' => 'Jl. Siliwangi No. 88, Kota Cirebon, Jawa Barat',
                'jumlah_value_nota' => 1200000,
                'tanggal_pengiriman' => '2026-11-24',
                'nama_penerima' => 'Anton',
            ]);
        });

        $this->assertNull(CsaShipment::where('no_sj', 'SJ-BELUM-CSA')->first());

        Livewire::test(ListCsaShipments::class)
            ->callAction('syncTrackingOrders')
            ->assertNotified('Sinkronisasi Surat Jalan selesai');

        // SJ yang CSA-nya belum upload otomatis masuk Data Pengiriman
        $created = CsaShipment::where('no_sj', 'SJ-BELUM-CSA')->sole();
        $this->assertSame(CsaShipment::SOURCE_SURAT_JALAN, $created->source);
        $this->assertSame('TOKO BARU', $created->tujuan_dealer);
        $this->assertSame('Jl. Siliwangi No. 88, Kota Cirebon, Jawa Barat', $created->alamat_kirim);
        $this->assertSame('CIREBON', $created->nama_kota);
        $this->assertSame('INTERNAL', $created->nama_ekspedisi);
        $this->assertNull($created->status_pengiriman);

        // Baris CSA yang sudah ada dilengkapi kolom kosongnya; data CSA & admin tetap utuh
        $existingCsa->refresh();
        $this->assertSame('DEALER RESMI CSA', $existingCsa->tujuan_dealer);
        $this->assertSame('RAX', $existingCsa->nama_ekspedisi);
        $this->assertSame('Kisabalanang, Marikangen, Kec. Plumbon, Kabupaten Cirebon', $existingCsa->alamat_kirim);
        $this->assertSame('KAB. CIREBON', $existingCsa->nama_kota);
        $this->assertNull($existingCsa->status_pengiriman);
        $this->assertSame('2026-11-23', $existingCsa->tanggal_diterima?->toDateString());

        // Tombol di halaman Surat Jalan juga berjalan aman (idempoten)
        Livewire::test(ListTrackingOrders::class)
            ->callAction('syncToDataPengiriman')
            ->assertNotified('Sinkronisasi ke Data Pengiriman selesai');
    }

    private function courierWithPanelAccess(): User
    {
        $permissions = collect(['ViewAny', 'View', 'Create'])
            ->map(fn (string $ability): Permission => Permission::findOrCreate("{$ability}:TrackingOrder", 'web'));

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('panel_user', 'web'));
        $user->givePermissionTo($permissions);

        return $user;
    }
}

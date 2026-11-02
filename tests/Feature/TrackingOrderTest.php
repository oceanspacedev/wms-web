<?php

namespace Tests\Feature;

use App\Filament\Resources\TrackingOrders\Pages\CreateTrackingOrder;
use App\Filament\Resources\TrackingOrders\Pages\ListTrackingOrders;
use App\Models\TrackingOrder;
use App\Models\User;
use App\Services\TrackingOrderExporter;
use Database\Seeders\TrackingOrderSeeder;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
            ->assertCanSeeTableRecords([$october])
            ->callAction('export')
            ->assertNotified('Export dimulai');

        // Antrean di test berjalan langsung: file tersimpan di disk privat & notifikasi berisi tombol Download
        $export = Export::sole();
        $this->assertNotNull($export->completed_at);
        $this->assertSame(2, $export->successful_rows);
        $file = $export->getFileDirectory().'/'.$export->file_name.'.xlsx';
        Storage::disk('local')->assertExists($file);

        $sheet = IOFactory::load(Storage::disk('local')->path($file))->getActiveSheet();
        $this->assertSame(TrackingOrderExporter::HEADERS, $sheet->rangeToArray('A1:P1')[0]);
        $this->assertSame(3, $sheet->getHighestRow());
        $this->assertSame('2610000123', $sheet->getCell('B2')->getValue());
        $this->assertEquals(1500000, $sheet->getCell('E2')->getValue());
        $this->assertSame('Imas', $sheet->getCell('H2')->getValue());
        // Foto nota tertempel di kolom FOTO NOTA; foto penerima yang filenya hilang diberi keterangan
        $this->assertSame(['I2'], array_map(fn ($drawing): string => $drawing->getCoordinates(), iterator_to_array($sheet->getDrawingCollection())));
        $this->assertSame('Foto tidak ditemukan', $sheet->getCell('J2')->getValue());
        $this->assertSame('Format foto tidak didukung', $sheet->getCell('I3')->getValue());
        $this->assertSame('-', $sheet->getCell('J3')->getValue());
        $this->assertStringEndsWith("/admin/tracking-orders/{$october->id}", $sheet->getCell('P2')->getHyperlink()->getUrl());

        $notification = $courier->notifications()->sole();
        $this->assertSame('Export Surat Jalan selesai', $notification->data['title']);
        $this->get($notification->data['actions'][0]['url'])->assertOk()->assertDownload($export->file_name.'.xlsx');
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

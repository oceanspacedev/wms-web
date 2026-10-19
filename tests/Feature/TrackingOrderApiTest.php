<?php

namespace Tests\Feature;

use App\Models\TrackingOrder;
use App\Models\User;
use Database\Seeders\TrackingOrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrackingOrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_tracking_orders(): void
    {
        $this->getJson('/api/tracking-orders')->assertUnauthorized();
    }

    public function test_can_list_tracking_orders_and_filter_by_courier(): void
    {
        $courier = $this->actAsCourier();
        $this->seed(TrackingOrderSeeder::class);
        TrackingOrder::query()->update(['user_id' => $courier->id]);

        $response = $this->getJson('/api/tracking-orders?kurir=Heidy');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'no_sj',
                        'nama_dealer',
                        'alamat_dealer',
                        'jumlah_value_nota',
                        'tanggal_nota',
                        'tanggal_pengiriman',
                        'nama_pengirim',
                        'nama_penerima',
                        'foto_nota_sj_url',
                        'foto_penerima_url',
                        'address',
                        'latitude',
                        'longitude',
                    ],
                ],
            ]);

        $this->assertTrue(collect($response->json('data'))->every(fn ($item) => str_contains($item['nama_pengirim'], 'Heidy')));

        $first = $response->json('data.0');
        $this->assertIsArray($first);
        $this->assertArrayNotHasKey('jumlah_value_nota_formatted', $first);
        $this->assertArrayNotHasKey('foto_nota_sj', $first);
        $this->assertArrayNotHasKey('foto_penerima', $first);
        $this->assertArrayNotHasKey('is_delivered', $first);
    }

    public function test_can_find_tracking_order_by_surat_jalan_barcode(): void
    {
        $courier = $this->actAsCourier();
        $this->seed(TrackingOrderSeeder::class);
        TrackingOrder::query()->update(['user_id' => $courier->id]);

        $order = TrackingOrder::firstOrFail();

        $response = $this->getJson("/api/tracking-orders/by-sj/{$order->no_sj}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.no_sj', $order->no_sj);

        $notFound = $this->getJson('/api/tracking-orders/by-sj/NON-EXISTENT-SJ-999');
        $notFound->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_lookup_by_sj_accepts_numbers_with_slashes(): void
    {
        $courier = $this->actAsCourier();
        TrackingOrder::create(['no_sj' => 'SJ/CRB/0007', 'nama_dealer' => 'Toko', 'user_id' => $courier->id]);

        $this->getJson('/api/tracking-orders/by-sj/'.rawurlencode('SJ/CRB/0007'))
            ->assertOk()
            ->assertJsonPath('data.no_sj', 'SJ/CRB/0007');
    }

    public function test_can_get_courier_drivers_list_and_summary(): void
    {
        $this->actAsCourier();
        $this->seed(TrackingOrderSeeder::class);

        $driversResponse = $this->getJson('/api/courier/drivers');
        $driversResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);

        $summaryResponse = $this->getJson('/api/courier/summary?kurir=Heidy');
        $summaryResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'total_assigned',
                    'total_delivered',
                    'total_pending',
                    'delivery_rate_percentage',
                ],
            ])
            ->assertJsonMissingPath('data.total_in_transit')
            ->assertJsonMissingPath('data.total_returned');
    }

    public function test_courier_records_every_column_in_one_request_at_the_receiving_point(): void
    {
        $courier = $this->actAsCourier();
        Storage::fake();

        $payload = [
            'no_sj' => 'SJ-MOBILE-NEW-999',
            'nama_dealer' => 'Toko Abadi Jaya Selular',
            'alamat_dealer' => 'Jl. Asia Afrika No. 45, Bandung',
            'jumlah_value_nota' => 1500000,
            'tanggal_nota' => '2026-10-01',
            'tanggal_pengiriman' => '2026-10-02',
            'nama_pengirim' => 'Supir Budi',
            'nama_penerima' => 'Ibu Siti (Kepala Toko)',
            'address' => 'Jl. Asia Afrika No. 47, Braga, Bandung',
            'latitude' => -6.917464,
            'longitude' => 107.619123,
            'notes' => 'Pengiriman koli 3 box handphone',
            'foto_nota_sj' => UploadedFile::fake()->image('nota_sj.jpg', 600, 400),
            'foto_penerima' => UploadedFile::fake()->image('penerima.jpg', 600, 400),
        ];

        $response = $this->postJson('/api/tracking-orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.no_sj', 'SJ-MOBILE-NEW-999')
            ->assertJsonPath('data.nama_dealer', 'Toko Abadi Jaya Selular')
            ->assertJsonPath('data.alamat_dealer', 'Jl. Asia Afrika No. 45, Bandung')
            ->assertJsonPath('data.jumlah_value_nota', 1500000)
            ->assertJsonPath('data.tanggal_nota', '2026-10-01')
            ->assertJsonPath('data.tanggal_pengiriman', '2026-10-02')
            ->assertJsonPath('data.nama_pengirim', 'Supir Budi')
            ->assertJsonPath('data.nama_penerima', 'Ibu Siti (Kepala Toko)')
            ->assertJsonPath('data.address', 'Jl. Asia Afrika No. 47, Braga, Bandung')
            ->assertJsonPath('data.latitude', -6.917464)
            ->assertJsonPath('data.longitude', 107.619123)
            ->assertJsonPath('data.notes', 'Pengiriman koli 3 box handphone')
            ->assertJsonPath('data.foto_nota_sj_url', fn ($url) => is_string($url) && str_contains($url, 'tracking-orders/nota/'))
            ->assertJsonPath('data.foto_penerima_url', fn ($url) => is_string($url) && str_contains($url, 'tracking-orders/penerima/'))
            ->assertJsonMissingPath('data.status');

        $order = TrackingOrder::where('no_sj', 'SJ-MOBILE-NEW-999')->firstOrFail();
        $this->assertSame($courier->id, $order->user_id);
        $this->assertSame('Ibu Siti (Kepala Toko)', $order->nama_penerima);
        $this->assertSame('2026-10-01', $order->tanggal_nota->toDateString());
        $this->assertEquals(-6.917464, $order->latitude);
        Storage::assertExists($order->foto_nota_sj);
        Storage::assertExists($order->foto_penerima);
        $this->assertMatchesRegularExpression('#^tracking-orders/nota/sj-mobile-new-999_nota_\d{8}-\d{6}_[a-z0-9]{5}\.jpg$#', $order->foto_nota_sj);
        $this->assertMatchesRegularExpression('#^tracking-orders/penerima/sj-mobile-new-999_penerima_\d{8}-\d{6}_[a-z0-9]{5}\.jpg$#', $order->foto_penerima);
    }

    public function test_submit_pod_endpoints_are_gone(): void
    {
        $this->actAsCourier();

        $order = TrackingOrder::create(['no_sj' => 'SJ-NO-POD', 'nama_dealer' => 'Toko']);

        $this->postJson("/api/tracking-orders/{$order->id}/submit-pod", ['nama_penerima' => 'X'])
            ->assertNotFound();
        // The by-sj lookup now owns the rest of the path, so the old POST URL is a method mismatch.
        $this->postJson('/api/tracking-orders/by-sj/SJ-NO-POD/submit-pod', ['nama_penerima' => 'X'])
            ->assertMethodNotAllowed();
    }

    public function test_user_cannot_create_tracking_order_with_invalid_coordinates(): void
    {
        $this->actAsCourier();

        $response = $this->postJson('/api/tracking-orders', [
            'no_sj' => 'SJ-INVALID-COORDS',
            'nama_dealer' => 'Toko Test',
            'latitude' => 120, // out of range (-90 to 90)
            'longitude' => 200, // out of range (-180 to 180)
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_user_cannot_create_tracking_order_with_duplicate_no_sj(): void
    {
        $this->actAsCourier();

        TrackingOrder::create([
            'no_sj' => 'SJ-EXISTING-123',
            'nama_dealer' => 'Dealer Lama',
        ]);

        $response = $this->postJson('/api/tracking-orders', [
            'no_sj' => 'SJ-EXISTING-123',
            'nama_dealer' => 'Dealer Baru',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['no_sj']);
    }

    public function test_user_cannot_create_tracking_order_without_required_fields(): void
    {
        $this->actAsCourier();

        $response = $this->postJson('/api/tracking-orders', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['no_sj', 'nama_dealer', 'nama_penerima', 'foto_nota_sj', 'foto_penerima']);
    }

    public function test_courier_only_sees_their_own_tracking_orders(): void
    {
        $otherCourier = User::factory()->create(['name' => 'Kurir Lain']);
        $otherOrder = TrackingOrder::create([
            'no_sj' => 'SJ-LAIN-001',
            'nama_dealer' => 'Toko Lain',
            'nama_pengirim' => 'Kurir Lain',
            'tanggal_pengiriman' => now()->toDateString(),
            'user_id' => $otherCourier->id,
        ]);

        $courier = $this->actAsCourier();
        $ownOrder = TrackingOrder::create([
            'no_sj' => 'SJ-SENDIRI-001',
            'nama_dealer' => 'Toko Sendiri',
            'nama_pengirim' => $courier->name,
            'nama_penerima' => 'Penerima',
            'tanggal_pengiriman' => now()->toDateString(),
            'user_id' => $courier->id,
        ]);

        $this->getJson('/api/tracking-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.no_sj', $ownOrder->no_sj);

        $this->getJson("/api/tracking-orders/{$ownOrder->id}")->assertOk();
        $this->getJson("/api/tracking-orders/{$otherOrder->id}")->assertNotFound();
        $this->getJson('/api/tracking-orders/by-sj/SJ-LAIN-001')->assertNotFound();

        $this->getJson('/api/courier/drivers')
            ->assertOk()
            ->assertJsonPath('data', [$courier->name]);

        $this->getJson('/api/courier/summary?kurir=Kurir Lain')
            ->assertOk()
            ->assertJsonPath('data.total_assigned', 0);

        $this->getJson('/api/courier/summary')
            ->assertOk()
            ->assertJsonPath('data.total_assigned', 1)
            ->assertJsonPath('data.total_delivered', 1);
    }

    public function test_super_admin_sees_every_tracking_order(): void
    {
        $courier = User::factory()->create();
        $order = TrackingOrder::create(['no_sj' => 'SJ-KURIR-001', 'nama_dealer' => 'Toko', 'user_id' => $courier->id]);
        TrackingOrder::create(['no_sj' => 'SJ-TANPA-PEMILIK', 'nama_dealer' => 'Toko']);

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/tracking-orders')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/tracking-orders/{$order->id}")->assertOk();
        $this->getJson('/api/tracking-orders/by-sj/SJ-KURIR-001')->assertOk();
    }

    private function actAsCourier(): User
    {
        $role = Role::findOrCreate('panel_user', 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }
}

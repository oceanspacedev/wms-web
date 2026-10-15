<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\PurchaseOrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_purchase_orders(): void
    {
        $this->getJson('/api/purchase-orders')->assertUnauthorized();
        $this->postJson('/api/purchase-orders', [])->assertUnauthorized();
    }

    public function test_can_list_purchase_orders_and_filter(): void
    {
        $user = $this->actAsUser();
        $this->seed(PurchaseOrderSeeder::class);
        PurchaseOrder::query()->update(['user_id' => $user->id]);

        $response = $this->getJson('/api/purchase-orders?status_penerimaan=Lengkap');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'no_po',
                        'no_do',
                        'tanggal_do',
                        'tanggal_datang',
                        'nama_supplier',
                        'nama_gudang',
                        'alamat_gudang',
                        'nama_kurir_ekspedisi',
                        'no_resi',
                        'penerima_gudang',
                        'qty_koli',
                        'qty_unit',
                        'keterangan_barang',
                        'status_penerimaan',
                        'catatan_gudang',
                        'foto_do_url',
                        'foto_resi_url',
                        'foto_penerimaan_url',
                        'address',
                        'latitude',
                        'longitude',
                    ],
                ],
            ]);

        $first = $response->json('data.0');
        $this->assertIsArray($first);
        $this->assertArrayNotHasKey('foto_do', $first);
        $this->assertArrayNotHasKey('total_nominal', $first);
        $this->assertArrayNotHasKey('status_verifikasi_finance', $first);
        $this->assertArrayNotHasKey('catatan_finance', $first);

        $this->assertTrue(collect($response->json('data'))->every(fn ($item) => $item['status_penerimaan'] === 'Lengkap'));
    }

    public function test_can_find_purchase_order_by_po_and_do(): void
    {
        $user = $this->actAsUser();
        $this->seed(PurchaseOrderSeeder::class);
        PurchaseOrder::query()->update(['user_id' => $user->id]);

        $order = PurchaseOrder::firstOrFail();

        $byPo = $this->getJson("/api/purchase-orders/by-po/{$order->no_po}");
        $byPo->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.no_po', $order->no_po);

        $byDo = $this->getJson("/api/purchase-orders/by-do/{$order->no_do}");
        $byDo->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.no_do', $order->no_do);
    }

    public function test_warehouse_records_do_resi_and_receiving_photo_in_one_request_without_knowing_the_po(): void
    {
        $user = $this->actAsUser();
        Storage::fake('public');

        $payload = [
            'no_do' => '20240506_63',
            'nama_supplier' => 'PT Aris Keep Going',
            'tanggal_do' => '2026-10-01',
            'nama_gudang' => 'CV TOP SELULAR - DL',
            'alamat_gudang' => 'Jl. Petratean No. 118, Pekalipan, Cirebon',
            'nama_kurir_ekspedisi' => '21 Express',
            'no_resi' => '100239400253',
            'qty_koli' => 3,
            'qty_unit' => 30,
            'keterangan_barang' => 'realme Note 80 64GB 4GB Storm Black',
            'status_penerimaan' => 'Lengkap',
            'address' => 'Jl. Petratean No. 118, Cirebon',
            'latitude' => -6.717,
            'longitude' => 108.557,
            'foto_do' => UploadedFile::fake()->image('do.jpg', 600, 400),
            'foto_resi' => UploadedFile::fake()->image('resi.jpg', 600, 400),
            'foto_penerimaan' => UploadedFile::fake()->image('penerimaan.jpg', 600, 400),
        ];

        $response = $this->postJson('/api/purchase-orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Penerimaan barang DO 20240506_63 berhasil dicatat.')
            ->assertJsonPath('data.no_po', null)
            ->assertJsonPath('data.no_do', '20240506_63')
            ->assertJsonPath('data.tanggal_do', '2026-10-01')
            ->assertJsonPath('data.tanggal_datang', now()->toDateString())
            ->assertJsonPath('data.nama_kurir_ekspedisi', '21 Express')
            ->assertJsonPath('data.no_resi', '100239400253')
            ->assertJsonPath('data.qty_koli', 3)
            ->assertJsonPath('data.qty_unit', 30)
            ->assertJsonPath('data.address', 'Jl. Petratean No. 118, Cirebon')
            ->assertJsonPath('data.latitude', -6.717)
            ->assertJsonPath('data.longitude', 108.557)
            ->assertJsonPath('data.foto_do_url', fn ($url) => is_string($url) && str_contains($url, 'purchase-orders/do/'))
            ->assertJsonPath('data.foto_resi_url', fn ($url) => is_string($url) && str_contains($url, 'purchase-orders/resi/'))
            ->assertJsonPath('data.foto_penerimaan_url', fn ($url) => is_string($url) && str_contains($url, 'purchase-orders/penerimaan/'))
            ->assertJsonMissingPath('data.total_nominal');

        $this->assertDatabaseHas('purchase_orders', [
            'no_do' => '20240506_63',
            'no_po' => null,
            'status_penerimaan' => 'Lengkap',
            'status_verifikasi_finance' => 'Menunggu Pemeriksaan',
        ]);

        $po = PurchaseOrder::where('no_do', '20240506_63')->firstOrFail();
        $this->assertSame($user->id, $po->user_id);

        $this->getJson("/api/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $po->id)
            ->assertJsonPath('data.no_do', '20240506_63');
        Storage::disk('public')->assertExists($po->foto_do);
        Storage::disk('public')->assertExists($po->foto_resi);
        Storage::disk('public')->assertExists($po->foto_penerimaan);
    }

    public function test_resi_is_optional_for_goods_delivered_by_the_supplier_fleet(): void
    {
        $this->actAsUser();
        Storage::fake('public');

        $this->postJson('/api/purchase-orders', [
            'no_po' => 'PO-ARMADA-01',
            'no_do' => 'DO-ARMADA-01',
            'nama_supplier' => 'PT Armada Sendiri',
            'status_penerimaan' => 'Lengkap',
            'foto_do' => UploadedFile::fake()->image('do.jpg'),
            'foto_penerimaan' => UploadedFile::fake()->image('penerimaan.jpg'),
        ])->assertCreated()
            ->assertJsonPath('data.no_resi', null)
            ->assertJsonPath('data.foto_resi_url', null);
    }

    public function test_short_or_damaged_receipt_needs_a_note(): void
    {
        $this->actAsUser();
        Storage::fake('public');

        $payload = [
            'no_po' => 'PO-KURANG-01',
            'no_do' => 'DO-KURANG-01',
            'nama_supplier' => 'PT Supplier',
            'status_penerimaan' => 'Kurang',
            'foto_do' => UploadedFile::fake()->image('do.jpg'),
            'foto_penerimaan' => UploadedFile::fake()->image('penerimaan.jpg'),
        ];

        $this->postJson('/api/purchase-orders', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['catatan_gudang']);

        $payload['catatan_gudang'] = 'Kurang 2 unit dari DO';
        $payload['foto_do'] = UploadedFile::fake()->image('do.jpg');
        $payload['foto_penerimaan'] = UploadedFile::fake()->image('penerimaan.jpg');

        $this->postJson('/api/purchase-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status_penerimaan', 'Kurang');
    }

    public function test_status_belum_datang_is_rejected_because_goods_are_recorded_on_arrival(): void
    {
        $this->actAsUser();

        $this->postJson('/api/purchase-orders', ['status_penerimaan' => 'Belum Datang'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status_penerimaan']);
    }

    public function test_lookups_accept_document_numbers_with_slashes(): void
    {
        $user = $this->actAsUser();
        PurchaseOrder::factory()->create(['no_do' => 'SJ/MKM/0042', 'no_po' => 'PO/2026/10/001', 'user_id' => $user->id]);

        $this->getJson('/api/purchase-orders/by-do/'.rawurlencode('SJ/MKM/0042'))
            ->assertOk()
            ->assertJsonPath('data.no_do', 'SJ/MKM/0042');
        $this->getJson('/api/purchase-orders/by-po/'.rawurlencode('PO/2026/10/001'))
            ->assertOk()
            ->assertJsonPath('data.no_po', 'PO/2026/10/001');
    }

    public function test_the_same_do_cannot_be_received_twice(): void
    {
        $this->actAsUser();

        PurchaseOrder::factory()->create(['no_do' => 'DO-SUDAH-DITERIMA']);

        $this->postJson('/api/purchase-orders', ['no_do' => 'DO-SUDAH-DITERIMA'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.no_do.0', 'DO ini sudah pernah dicatat diterima.');
    }

    public function test_user_cannot_create_purchase_order_with_duplicate_no_po(): void
    {
        $this->actAsUser();

        PurchaseOrder::create([
            'no_po' => 'PO-EXISTING-123',
            'nama_supplier' => 'Supplier Lama',
            'qty_koli' => 1,
            'qty_unit' => 10,
            'status_penerimaan' => 'Lengkap',
            'status_verifikasi_finance' => 'Menunggu Pemeriksaan',
        ]);

        $response = $this->postJson('/api/purchase-orders', [
            'no_po' => 'PO-EXISTING-123',
            'nama_supplier' => 'Supplier Baru',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['no_po']);
    }

    public function test_user_cannot_create_purchase_order_without_required_fields(): void
    {
        $this->actAsUser();

        $response = $this->postJson('/api/purchase-orders', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['no_do', 'nama_supplier', 'status_penerimaan', 'foto_do', 'foto_penerimaan'])
            ->assertJsonMissingValidationErrors(['no_po', 'foto_resi', 'no_resi']);
    }

    public function test_warehouse_staff_only_sees_their_own_purchase_orders(): void
    {
        $otherStaff = User::factory()->create();
        $otherOrder = PurchaseOrder::factory()->create(['no_do' => 'DO-LAIN-001', 'no_po' => 'PO-LAIN-001', 'user_id' => $otherStaff->id]);

        $user = $this->actAsUser();
        $ownOrder = PurchaseOrder::factory()->create(['no_do' => 'DO-SENDIRI-001', 'user_id' => $user->id]);

        $this->getJson('/api/purchase-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.no_do', $ownOrder->no_do);

        $this->getJson("/api/purchase-orders/{$ownOrder->id}")->assertOk();
        $this->getJson("/api/purchase-orders/{$otherOrder->id}")->assertNotFound();
        $this->getJson('/api/purchase-orders/by-do/DO-LAIN-001')->assertNotFound();
        $this->getJson('/api/purchase-orders/by-po/PO-LAIN-001')->assertNotFound();
    }

    public function test_super_admin_sees_every_purchase_order(): void
    {
        $staff = User::factory()->create();
        $order = PurchaseOrder::factory()->create(['no_do' => 'DO-STAFF-001', 'user_id' => $staff->id]);
        PurchaseOrder::factory()->create();

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/purchase-orders')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/purchase-orders/{$order->id}")->assertOk();
        $this->getJson('/api/purchase-orders/by-do/DO-STAFF-001')->assertOk();
    }

    private function actAsUser(): User
    {
        $role = Role::findOrCreate('panel_user', 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }
}

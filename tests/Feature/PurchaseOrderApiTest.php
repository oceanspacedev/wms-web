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
        $this->actAsUser();
        $this->seed(PurchaseOrderSeeder::class);

        $response = $this->getJson('/api/purchase-orders?status_penerimaan=Lengkap');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'no_po',
                        'no_sj_supplier',
                        'tanggal_po',
                        'tanggal_datang',
                        'nama_supplier',
                        'nama_gudang',
                        'alamat_gudang',
                        'nama_kurir_ekspedisi',
                        'no_resi',
                        'penerima_gudang',
                        'qty_koli',
                        'qty_unit',
                        'total_nominal',
                        'keterangan_barang',
                        'status_penerimaan',
                        'catatan_gudang',
                        'bukti_serah_terima_url',
                    ],
                ],
            ]);

        $first = $response->json('data.0');
        $this->assertIsArray($first);
        $this->assertArrayNotHasKey('bukti_serah_terima', $first);
        $this->assertArrayNotHasKey('status_verifikasi_finance', $first);
        $this->assertArrayNotHasKey('catatan_finance', $first);

        $this->assertTrue(collect($response->json('data'))->every(fn ($item) => $item['status_penerimaan'] === 'Lengkap'));
    }

    public function test_can_find_purchase_order_by_po_and_sj(): void
    {
        $this->actAsUser();
        $this->seed(PurchaseOrderSeeder::class);

        $order = PurchaseOrder::firstOrFail();

        $byPo = $this->getJson("/api/purchase-orders/by-po/{$order->no_po}");
        $byPo->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.no_po', $order->no_po);

        $bySj = $this->getJson("/api/purchase-orders/by-sj/{$order->no_sj_supplier}");
        $bySj->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.no_sj_supplier', $order->no_sj_supplier);
    }

    public function test_user_can_create_purchase_order_via_api(): void
    {
        $this->actAsUser();
        Storage::fake('public');

        $photo = UploadedFile::fake()->image('bukti_do.jpg', 600, 400);

        $payload = [
            'no_po' => 'PO-MOBILE-NEW-001',
            'no_sj_supplier' => 'SJ-SUPP-9912',
            'nama_supplier' => 'PT Harapan Indah Jaya',
            'nama_gudang' => 'GUDANG MSIS BANDUNG',
            'alamat_gudang' => 'Jl. Soekarno Hatta No. 100, Bandung',
            'nama_kurir_ekspedisi' => 'J&T Cargo',
            'no_resi' => 'AWB-JT-887711',
            'qty_koli' => 5,
            'qty_unit' => 200,
            'total_nominal' => 25000000,
            'keterangan_barang' => 'Pengiriman 5 Koli Smartphone Galaxy A55',
            'status_penerimaan' => 'Lengkap',
            'catatan_gudang' => 'Kemasan utuh dan segel resmi',
            'bukti_serah_terima' => $photo,
        ];

        $response = $this->postJson('/api/purchase-orders', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.no_po', 'PO-MOBILE-NEW-001')
            ->assertJsonPath('data.nama_supplier', 'PT Harapan Indah Jaya')
            ->assertJsonPath('data.qty_koli', 5)
            ->assertJsonPath('data.qty_unit', 200);

        $this->assertDatabaseHas('purchase_orders', [
            'no_po' => 'PO-MOBILE-NEW-001',
            'nama_supplier' => 'PT Harapan Indah Jaya',
            'status_penerimaan' => 'Lengkap',
            'status_verifikasi_finance' => 'Menunggu Pemeriksaan',
        ]);

        $po = PurchaseOrder::where('no_po', 'PO-MOBILE-NEW-001')->firstOrFail();
        $this->assertNotNull($po->bukti_serah_terima);
        Storage::disk('public')->assertExists($po->bukti_serah_terima);
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
            ->assertJsonValidationErrors(['no_po', 'nama_supplier']);
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

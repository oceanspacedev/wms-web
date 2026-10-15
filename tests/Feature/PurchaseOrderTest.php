<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\PurchaseOrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_purchase_orders_index(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin/purchase-orders');
        $response->assertStatus(200);
    }

    public function test_purchase_orders_can_be_seeded_and_queried(): void
    {
        $this->seed();
        $this->seed(PurchaseOrderSeeder::class);

        $this->assertDatabaseHas('purchase_orders', [
            'no_po' => 'PO-202609-0012',
            'no_do' => 'SJ-MKM-8821',
            'nama_supplier' => 'PT Sumber Makmur Pratama',
            'status_penerimaan' => 'Lengkap',
            'status_verifikasi_finance' => 'Disetujui',
        ]);

        $po = PurchaseOrder::where('no_po', 'PO-202609-0012')->firstOrFail();
        $this->assertSame('GUDANG MSIS BANDUNG', $po->nama_gudang);
    }

    public function test_super_admin_can_access_create_and_view_purchase_order(): void
    {
        $this->seed();
        $this->seed(PurchaseOrderSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $po = PurchaseOrder::firstOrFail();

        $createResponse = $this->actingAs($admin)->get('/admin/purchase-orders/create');
        $createResponse->assertStatus(200);

        $viewResponse = $this->actingAs($admin)->get("/admin/purchase-orders/{$po->id}");
        $viewResponse->assertStatus(200);
    }
}

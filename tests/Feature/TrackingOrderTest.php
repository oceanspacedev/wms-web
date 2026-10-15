<?php

namespace Tests\Feature;

use App\Models\TrackingOrder;
use App\Models\User;
use Database\Seeders\TrackingOrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

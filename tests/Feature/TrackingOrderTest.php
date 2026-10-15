<?php

namespace Tests\Feature;

use App\Filament\Resources\TrackingOrders\Pages\CreateTrackingOrder;
use App\Filament\Resources\TrackingOrders\Pages\ListTrackingOrders;
use App\Models\TrackingOrder;
use App\Models\User;
use Database\Seeders\TrackingOrderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

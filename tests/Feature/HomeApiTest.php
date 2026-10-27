<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\TrackingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HomeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-27 09:00:00');
    }

    public function test_guest_cannot_open_home(): void
    {
        $this->getJson('/api/home/today')->assertUnauthorized();
    }

    public function test_home_only_contains_todays_records_owned_by_the_user(): void
    {
        $user = $this->actAsUser();
        $otherUser = User::factory()->create();

        $delivered = TrackingOrder::create([
            'no_sj' => 'SJ-TODAY-01',
            'nama_dealer' => 'Dealer A',
            'tanggal_pengiriman' => '2026-10-27',
            'nama_penerima' => 'Pak Joko',
            'user_id' => $user->id,
        ]);
        $pending = TrackingOrder::create([
            'no_sj' => 'SJ-TODAY-02',
            'nama_dealer' => 'Dealer B',
            'tanggal_pengiriman' => '2026-10-27',
            'user_id' => $user->id,
        ]);
        $undated = TrackingOrder::create([
            'no_sj' => 'SJ-NO-DATE',
            'nama_dealer' => 'Dealer C',
            'user_id' => $user->id,
        ]);
        TrackingOrder::create([
            'no_sj' => 'SJ-YESTERDAY',
            'nama_dealer' => 'Dealer D',
            'tanggal_pengiriman' => '2026-10-26',
            'user_id' => $user->id,
        ]);
        TrackingOrder::create([
            'no_sj' => 'SJ-OTHER-USER',
            'nama_dealer' => 'Dealer E',
            'tanggal_pengiriman' => '2026-10-27',
            'user_id' => $otherUser->id,
        ]);

        $complete = PurchaseOrder::factory()->create([
            'tanggal_datang' => '2026-10-27',
            'status_penerimaan' => 'Lengkap',
            'user_id' => $user->id,
        ]);
        $short = PurchaseOrder::factory()->create([
            'tanggal_datang' => '2026-10-27',
            'status_penerimaan' => 'Kurang',
            'user_id' => $user->id,
        ]);
        PurchaseOrder::factory()->create([
            'tanggal_datang' => '2026-10-20',
            'status_penerimaan' => 'Rusak',
            'user_id' => $user->id,
        ]);
        PurchaseOrder::factory()->create([
            'tanggal_datang' => '2026-10-27',
            'status_penerimaan' => 'Rusak',
            'user_id' => $otherUser->id,
        ]);

        $this->getJson('/api/home/today')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.date', '2026-10-27')
            ->assertJsonPath('data.tracking_orders.total', 3)
            ->assertJsonPath('data.tracking_orders.delivered', 1)
            ->assertJsonPath('data.tracking_orders.pending', 2)
            ->assertJsonPath('data.tracking_orders.items.*.id', [$undated->id, $pending->id, $delivered->id])
            ->assertJsonPath('data.purchase_orders.total', 2)
            ->assertJsonPath('data.purchase_orders.complete', 1)
            ->assertJsonPath('data.purchase_orders.short', 1)
            ->assertJsonPath('data.purchase_orders.damaged', 0)
            ->assertJsonPath('data.purchase_orders.items.*.id', [$short->id, $complete->id])
            ->assertJsonStructure([
                'data' => [
                    'tracking_orders' => ['items' => ['*' => ['id', 'no_sj', 'nama_dealer', 'tanggal_pengiriman']]],
                    'purchase_orders' => ['items' => ['*' => ['id', 'no_do', 'nama_supplier', 'status_penerimaan']]],
                ],
            ]);
    }

    public function test_home_limits_items_but_counts_every_record_of_today(): void
    {
        $user = $this->actAsUser();

        foreach (range(1, 7) as $number) {
            TrackingOrder::create([
                'no_sj' => "SJ-TODAY-{$number}",
                'nama_dealer' => 'Dealer',
                'tanggal_pengiriman' => '2026-10-27',
                'user_id' => $user->id,
            ]);
        }

        $this->getJson('/api/home/today')
            ->assertOk()
            ->assertJsonPath('data.tracking_orders.total', 7)
            ->assertJsonCount(5, 'data.tracking_orders.items')
            ->assertJsonPath('data.tracking_orders.items.0.no_sj', 'SJ-TODAY-7');

        $this->getJson('/api/home/today?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.tracking_orders.items');

        $this->getJson('/api/home/today?limit=999')
            ->assertOk()
            ->assertJsonCount(7, 'data.tracking_orders.items');
    }

    public function test_super_admin_sees_todays_records_of_every_user(): void
    {
        $courier = User::factory()->create();
        TrackingOrder::create([
            'no_sj' => 'SJ-KURIR-001',
            'nama_dealer' => 'Toko',
            'tanggal_pengiriman' => '2026-10-27',
            'user_id' => $courier->id,
        ]);
        PurchaseOrder::factory()->create(['tanggal_datang' => '2026-10-27', 'user_id' => $courier->id]);

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/home/today')
            ->assertOk()
            ->assertJsonPath('data.tracking_orders.total', 1)
            ->assertJsonPath('data.purchase_orders.total', 1);
    }

    private function actAsUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('panel_user', 'web'));
        $this->actingAs($user, 'sanctum');

        return $user;
    }
}

<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\TrackingOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAppReviewAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_demo_account_that_can_log_in_and_see_its_data(): void
    {
        $this->artisan('app-review:account', ['--password' => 'Review2026!'])->assertSuccessful();

        $user = User::query()->where('username', 'appreview')->firstOrFail();
        $this->assertTrue($user->hasRole('panel_user'));

        $token = $this->postJson('/api/login', ['login' => 'appreview', 'password' => 'Review2026!'])
            ->assertOk()
            ->json('data.access_token');

        $this->withToken($token)->getJson('/api/tracking-orders')->assertOk()->assertJsonFragment(['no_sj' => 'DEMO-SJ-0001']);
        $this->withToken($token)->getJson('/api/purchase-orders')->assertOk()->assertJsonFragment(['no_po' => 'DEMO-PO-0001']);
    }

    public function test_rerunning_resets_the_password_without_duplicating_data(): void
    {
        $this->artisan('app-review:account', ['--password' => 'FirstPass123'])->assertSuccessful();
        $this->artisan('app-review:account', ['--password' => 'SecondPass123'])->assertSuccessful();

        $this->assertSame(1, User::query()->where('username', 'appreview')->count());
        $this->assertSame(2, TrackingOrder::query()->count());
        $this->assertSame(2, PurchaseOrder::query()->count());

        $this->postJson('/api/login', ['login' => 'appreview', 'password' => 'FirstPass123'])->assertStatus(401);
        $this->postJson('/api/login', ['login' => 'appreview', 'password' => 'SecondPass123'])->assertOk();
    }
}

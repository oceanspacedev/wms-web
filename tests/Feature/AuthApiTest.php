<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\TrackingOrder;
use App\Models\User;
use App\Models\WhatsappOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_user_logs_in_with_username_and_receives_a_token(): void
    {
        $user = $this->panelUser([
            'username' => 'gudang',
            'email' => 'gudang@example.com',
            'password' => 'secret-pass',
        ]);

        $response = $this->postJson('/api/login', [
            'login' => 'Gudang',
            'password' => 'secret-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.username', 'gudang');

        $token = $response->json('data.access_token');
        $this->assertIsString($token);

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'gudang@example.com');
    }

    public function test_panel_user_logs_in_with_email(): void
    {
        $user = $this->panelUser([
            'email' => 'gudang@example.com',
            'password' => 'secret-pass',
        ]);

        $this->postJson('/api/login', [
            'login' => 'gudang@example.com',
            'password' => 'secret-pass',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->panelUser([
            'email' => 'gudang@example.com',
            'password' => 'secret-pass',
        ]);

        $this->postJson('/api/login', [
            'login' => 'gudang@example.com',
            'password' => 'wrong-pass',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Username, email, atau password salah.');
    }

    public function test_user_without_panel_access_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'outsider@example.com',
            'password' => 'secret-pass',
        ]);

        $this->postJson('/api/login', [
            'login' => 'outsider@example.com',
            'password' => 'secret-pass',
        ])->assertUnauthorized();
    }

    public function test_verified_user_logs_in_with_whatsapp_otp(): void
    {
        $user = $this->panelUser([
            'whatsapp_number' => '6281234567890',
            'whatsapp_verified_at' => now(),
        ]);

        $this->postJson('/api/login/whatsapp', [
            'whatsapp_number' => '081234567890',
        ])->assertOk()
            ->assertJsonPath('data.whatsapp_number', '*********7890')
            ->assertJsonPath('data.expires_in', 300);

        $otp = WhatsappOtp::query()->where('user_id', $user->id)->firstOrFail();
        $otp->forceFill(['otp_hash' => Hash::make('123456')])->save();

        $response = $this->postJson('/api/login/whatsapp/verify', [
            'whatsapp_number' => '081234567890',
            'otp' => '123456',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $token = $response->json('data.access_token');

        TrackingOrder::query()->create([
            'no_sj' => 'SJ-API-LOGIN-1',
            'nama_dealer' => 'Dealer Uji',
            'user_id' => $user->id,
        ]);

        $this->withToken($token)
            ->getJson('/api/tracking-orders/by-sj/SJ-API-LOGIN-1')
            ->assertOk()
            ->assertJsonPath('data.no_sj', 'SJ-API-LOGIN-1');
    }

    public function test_unverified_whatsapp_number_is_rejected(): void
    {
        $this->panelUser([
            'whatsapp_number' => '6281234567890',
            'whatsapp_verified_at' => null,
        ]);

        $this->postJson('/api/login/whatsapp', [
            'whatsapp_number' => '081234567890',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Nomor WhatsApp belum terdaftar atau belum aktif.');

        $this->assertSame(0, WhatsappOtp::query()->count());
    }

    public function test_logout_revokes_the_token(): void
    {
        $this->panelUser([
            'email' => 'gudang@example.com',
            'password' => 'secret-pass',
        ]);

        $token = $this->postJson('/api/login', [
            'login' => 'gudang@example.com',
            'password' => 'secret-pass',
        ])->json('data.access_token');

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout berhasil.');

        $this->assertSame(0, PersonalAccessToken::query()->count());

        // Clear the guard so the next request re-resolves auth from the (revoked) token.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_look_up_a_purchase_order(): void
    {
        $user = $this->panelUser();
        $token = $user->createToken('tracking')->plainTextToken;

        $order = PurchaseOrder::factory()->create([
            'no_po' => 'PO-202610-0001',
            'no_do' => 'DO-SUP-0001',
            'user_id' => $user->id,
        ]);

        $this->getJson('/api/purchase-orders/by-po/PO-202610-0001')
            ->assertUnauthorized();

        $this->withToken($token)
            ->getJson('/api/purchase-orders/by-po/PO-202610-0001')
            ->assertOk()
            ->assertJsonPath('data.no_po', $order->no_po);

        $this->withToken($token)
            ->getJson('/api/purchase-orders/by-do/DO-SUP-0001')
            ->assertOk()
            ->assertJsonPath('data.no_do', 'DO-SUP-0001');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function panelUser(array $attributes = []): User
    {
        $role = Role::findOrCreate('panel_user', 'web');
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}

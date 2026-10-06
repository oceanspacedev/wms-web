<?php

namespace Tests\Feature;

use App\Models\TrackingOrder;
use App\Models\User;
use App\Models\WhatsappOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WmsProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_me_returns_profile_wms_structure(): void
    {
        $role = Role::findOrCreate('kurir', 'web');
        $user = User::factory()->create([
            'name' => 'Budi Santoso',
            'username' => 'kurir01',
            'avatar_url' => 'avatars/test-avatar.jpg',
            'whatsapp_number' => '6281234567890',
            'whatsapp_verified_at' => now(),
        ]);
        $user->assignRole($role);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Profil berhasil dimuat.')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.username', 'kurir01')
            ->assertJsonPath('data.nama_lengkap', 'Budi Santoso')
            ->assertJsonPath('data.whatsapp_number', '6281234567890')
            ->assertJsonPath('data.role.id', $role->id)
            ->assertJsonPath('data.role.name', 'kurir')
            ->assertJsonPath('data.roles.0', 'kurir');

        $this->assertStringContainsString('avatars/test-avatar.jpg', (string) $response->json('data.profile_photo_url'));

        // Also test GET /api/user alias
        $aliasResponse = $this->actingAs($user, 'sanctum')->getJson('/api/user');
        $aliasResponse->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_courier_summary_uses_logged_in_courier_and_today_without_query(): void
    {
        $role = Role::findOrCreate('kurir', 'web');
        $user = User::factory()->create([
            'name' => 'Budi Santoso',
            'username' => 'kurir01',
        ]);
        $user->assignRole($role);

        $otherUser = User::factory()->create([
            'name' => 'Doni Driver',
            'username' => 'doni02',
        ]);
        $otherUser->assignRole($role);

        // 1. Order today for Budi - Delivered with POD photo
        TrackingOrder::create([
            'no_sj' => 'SJ-TODAY-01',
            'nama_dealer' => 'Dealer A',
            'nama_pengirim' => 'Budi Santoso',
            'tanggal_pengiriman' => now()->toDateString(),
            'foto_penerima' => 'penerima_1.jpg',
            'status' => 'DELIVERED',
        ]);

        // 2. Order today for Budi - Delivered with recipient name
        TrackingOrder::create([
            'no_sj' => 'SJ-TODAY-02',
            'nama_dealer' => 'Dealer B',
            'nama_pengirim' => 'Budi Santoso',
            'tanggal_pengiriman' => now()->toDateString(),
            'nama_penerima' => 'Pak Joko',
            'status' => 'DELIVERED',
        ]);

        // 3. Order today for Budi - Pending (no POD yet)
        TrackingOrder::create([
            'no_sj' => 'SJ-TODAY-03',
            'nama_dealer' => 'Dealer C',
            'nama_pengirim' => 'Budi Santoso',
            'tanggal_pengiriman' => now()->toDateString(),
            'nama_penerima' => null,
            'foto_penerima' => null,
            'status' => 'IN_TRANSIT',
        ]);

        // 4. Order yesterday for Budi (should not be counted in today's profile)
        TrackingOrder::create([
            'no_sj' => 'SJ-YESTERDAY-01',
            'nama_dealer' => 'Dealer D',
            'nama_pengirim' => 'Budi Santoso',
            'tanggal_pengiriman' => now()->subDay()->toDateString(),
            'status' => 'IN_TRANSIT',
        ]);

        // 5. Order today for Doni (different courier)
        TrackingOrder::create([
            'no_sj' => 'SJ-DONI-01',
            'nama_dealer' => 'Dealer E',
            'nama_pengirim' => 'Doni Driver',
            'tanggal_pengiriman' => now()->toDateString(),
            'status' => 'IN_TRANSIT',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/courier/summary');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_assigned', 3)
            ->assertJsonPath('data.total_delivered', 2)
            ->assertJsonPath('data.total_pending', 1);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('tracking')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/logout');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logout berhasil.')
            ->assertJsonPath('data', null);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_can_update_profile_name_username_and_password(): void
    {
        $user = User::factory()->create([
            'name' => 'Nama Lama',
            'username' => 'username_lama',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/user', [
            'nama_lengkap' => 'Nama Baru',
            'username' => 'username_baru',
            'password' => 'newpassword123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Profil berhasil diperbarui.')
            ->assertJsonPath('data.nama_lengkap', 'Nama Baru')
            ->assertJsonPath('data.username', 'username_baru');

        $user->refresh();
        $this->assertSame('Nama Baru', $user->name);
        $this->assertSame('username_baru', $user->username);
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    public function test_update_profile_rejects_duplicate_username(): void
    {
        User::factory()->create(['username' => 'sudah_ada']);
        $user = User::factory()->create(['username' => 'user_saya']);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/user', [
            'username' => 'sudah_ada',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);
    }

    public function test_can_update_profile_photo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar_url' => 'avatars/old-photo.jpg',
        ]);
        Storage::disk('public')->put('avatars/old-photo.jpg', 'old-content');

        $photo = UploadedFile::fake()->image('profile.png', 400, 400);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/user/photo', [
            'profile_photo' => $photo,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Foto profil berhasil diperbarui.');

        $user->refresh();
        $this->assertNotNull($user->avatar_url);
        $this->assertNotSame('avatars/old-photo.jpg', $user->avatar_url);
        Storage::disk('public')->assertExists($user->avatar_url);
        Storage::disk('public')->assertMissing('avatars/old-photo.jpg');
    }

    public function test_request_and_verify_whatsapp_otp_for_profile_update(): void
    {
        $user = User::factory()->create([
            'whatsapp_number' => '6281111111111',
            'whatsapp_verified_at' => now(),
        ]);

        // 1. Request OTP to new number
        $requestResponse = $this->actingAs($user, 'sanctum')->postJson('/api/user/whatsapp/request-otp', [
            'whatsapp_number' => '081234567890',
        ]);

        $requestResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'OTP berhasil dikirim ke nomor WhatsApp.');

        $otpRecord = WhatsappOtp::query()
            ->where('user_id', $user->id)
            ->where('purpose', WhatsappOtp::PURPOSE_UPDATE_WHATSAPP)
            ->firstOrFail();

        $otpRecord->forceFill(['otp_hash' => Hash::make('654321')])->save();

        // 2. Verify with wrong OTP
        $wrongResponse = $this->actingAs($user, 'sanctum')->postJson('/api/user/whatsapp/verify-otp', [
            'whatsapp_number' => '081234567890',
            'otp' => '000000',
        ]);

        $wrongResponse->assertUnprocessable()
            ->assertJsonPath('message', 'OTP tidak valid atau sudah kedaluwarsa.');

        // 3. Verify with correct OTP
        $verifyResponse = $this->actingAs($user, 'sanctum')->postJson('/api/user/whatsapp/verify-otp', [
            'whatsapp_number' => '081234567890',
            'otp' => '654321',
        ]);

        $verifyResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Nomor WhatsApp berhasil diperbarui.')
            ->assertJsonPath('data.whatsapp_number', '6281234567890');

        $user->refresh();
        $this->assertSame('6281234567890', $user->whatsapp_number);
        $this->assertNotNull($user->whatsapp_verified_at);
    }

    public function test_cannot_request_whatsapp_otp_with_number_already_used_by_another_user(): void
    {
        User::factory()->create([
            'whatsapp_number' => '6281234567890',
        ]);

        $user = User::factory()->create([
            'whatsapp_number' => '6289999999999',
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/user/whatsapp/request-otp', [
            'whatsapp_number' => '081234567890',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Nomor WhatsApp sudah digunakan oleh akun lain.');
    }

    public function test_can_delete_account(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar_url' => 'avatars/my-avatar.jpg',
        ]);
        Storage::disk('public')->put('avatars/my-avatar.jpg', 'avatar-data');
        $token = $user->createToken('tracking')->plainTextToken;

        $response = $this->withToken($token)->deleteJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Akun berhasil dihapus.')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, PersonalAccessToken::count());
        Storage::disk('public')->assertMissing('avatars/my-avatar.jpg');
    }
}

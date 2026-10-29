<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Jobs\SendUserAccountActivatedWhatsAppJob;
use App\Models\User;
use App\Services\WhatsAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserAccountActivatedWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_user_with_whatsapp_number_queues_one_whatsapp_message(): void
    {
        Queue::fake();
        $this->actingAs($this->userAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Kasir',
                'username' => 'kasir_01',
                'email' => 'kasir@example.com',
                'password' => 'secret-pass',
                'whatsapp_number' => '081298765432',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('username', 'kasir_01')->firstOrFail();

        Queue::assertPushed(SendUserAccountActivatedWhatsAppJob::class, 1);
        Queue::assertPushed(
            SendUserAccountActivatedWhatsAppJob::class,
            fn (SendUserAccountActivatedWhatsAppJob $job): bool => $job->userId === $user->id,
        );
    }

    public function test_creating_a_user_without_whatsapp_number_queues_nothing(): void
    {
        Queue::fake();
        $this->actingAs($this->userAdmin());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Kasir',
                'username' => 'kasir_02',
                'email' => 'kasir2@example.com',
                'password' => 'secret-pass',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(User::query()->where('username', 'kasir_02')->first());
        Queue::assertNotPushed(SendUserAccountActivatedWhatsAppJob::class);
    }

    public function test_job_sends_access_info_and_both_app_links_through_the_gateway(): void
    {
        $this->configureGateway();
        config([
            'services.wms_app.name' => 'WMS',
            'services.wms_app.playstore_url' => 'https://play.google.com/store/apps/details?id=com.mediaselularindonesia.sam',
            'services.wms_app.testflight_url' => 'https://testflight.apple.com/join/WMS123',
        ]);

        Http::preventStrayRequests();
        Http::fake(['https://wag.test/api/v1/messages' => Http::response(['ok' => true], 202)]);

        $user = User::factory()->withUsername('kasir_03')->create([
            'name' => 'Kasir Tiga',
            'email' => 'kasir3@example.com',
            'whatsapp_number' => '081298765433',
        ]);

        $this->runJob($user);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $text = $request['message']['text'];

            return $request['recipient']['value'] === '6281298765433'
                && str_starts_with($text, "Halo *Kasir Tiga*,\n\nNomor WhatsApp Anda sudah terdaftar di *WMS* dan bisa digunakan untuk login aplikasi.")
                && str_contains($text, "*Android (Play Store)*\nhttps://play.google.com/store/apps/details?id=com.mediaselularindonesia.sam\n")
                && str_contains($text, "*iPhone (TestFlight)*\nhttps://testflight.apple.com/join/WMS123\n")
                && str_contains($text, '2. Masukkan nomor WhatsApp ini.')
                && ! str_contains($text, 'Password');
        });
    }

    public function test_job_with_initial_password_also_sends_username_and_password(): void
    {
        $this->configureGateway();
        Http::preventStrayRequests();
        Http::fake(['https://wag.test/api/v1/messages' => Http::response(['ok' => true], 202)]);

        $user = User::factory()->withUsername('dinoliardii1')->create([
            'name' => 'Dino Liardi',
            'whatsapp_number' => '081298765435',
        ]);

        $this->runJob($user, 'k7mp2xq9ab');

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $text = $request['message']['text'];

            return str_contains($text, '3. Masukkan kode OTP yang dikirim ke WhatsApp Anda.')
                && str_contains($text, "Bisa juga login dengan username dan password:\nUsername: *dinoliardii1*\nPassword: *k7mp2xq9ab*\n");
        });
    }

    public function test_job_logs_and_does_not_throw_when_the_gateway_fails(): void
    {
        $this->configureGateway();
        Log::spy();
        Http::fake(['https://wag.test/api/v1/messages' => Http::response(['error' => 'down'], 500)]);

        $user = User::factory()->create(['whatsapp_number' => '081298765434']);

        $this->runJob($user);

        $this->assertModelExists($user);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Gagal mengirim WhatsApp akun aktif ke user baru'
                && $context['user_id'] === $user->id)
            ->once();
    }

    private function runJob(User $user, ?string $password = null): void
    {
        (new SendUserAccountActivatedWhatsAppJob($user->id, $password))->handle(app(WhatsAppNotificationService::class));
    }

    private function configureGateway(): void
    {
        config([
            'services.whatsapp_gateway.url' => 'https://wag.test',
            'services.whatsapp_gateway.token' => 'test-token',
        ]);
    }

    private function userAdmin(): User
    {
        $role = Role::findOrCreate('super_admin', 'web');

        foreach (['ViewAny:User', 'Create:User'] as $permissionName) {
            $role->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
        }

        $admin = User::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }
}

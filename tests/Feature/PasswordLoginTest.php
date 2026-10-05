<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PasswordLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shows_username_email_and_whatsapp_entry_when_wag_configured(): void
    {
        config([
            'services.whatsapp_gateway.url' => 'https://api.whatsapp.test',
            'services.whatsapp_gateway.token' => 'dummy-token',
        ]);

        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertSee('mky-auth-brand', false);
        $response->assertSee('Username atau email');
        $response->assertSee('Atau masuk dengan');
        $response->assertSee('WhatsApp');
        $response->assertSee(route('phone-login'), false);
    }

    public function test_login_page_hides_whatsapp_entry_when_wag_not_configured(): void
    {
        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
        ]);

        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertDontSee('Atau masuk dengan');
        $response->assertDontSee(route('phone-login'), false);
    }

    public function test_panel_user_signs_in_with_email_and_password(): void
    {
        $user = $this->panelUser([
            'email' => 'gudang@example.com',
            'password' => 'secret-pass',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'login' => 'gudang@example.com',
                'password' => 'secret-pass',
            ])
            ->call('authenticate')
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
    }

    public function test_panel_user_signs_in_with_username_and_password(): void
    {
        $user = $this->panelUser([
            'username' => 'gudang',
            'password' => 'secret-pass',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'login' => 'Gudang',
                'password' => 'secret-pass',
            ])
            ->call('authenticate')
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_keeps_the_guest_on_the_login_form(): void
    {
        $this->panelUser([
            'email' => 'gudang@example.com',
            'password' => 'secret-pass',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'login' => 'gudang@example.com',
                'password' => 'wrong-pass',
            ])
            ->call('authenticate')
            ->assertHasFormErrors([
                'login' => __('filament-panels::auth/pages/login.messages.failed'),
            ]);

        $this->assertGuest();
    }

    public function test_user_without_panel_access_cannot_sign_in_with_password(): void
    {
        User::factory()->create([
            'email' => 'outsider@example.com',
            'password' => 'secret-pass',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'login' => 'outsider@example.com',
                'password' => 'secret-pass',
            ])
            ->call('authenticate')
            ->assertHasFormErrors([
                'login' => __('filament-panels::auth/pages/login.messages.failed'),
            ]);

        $this->assertGuest();
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

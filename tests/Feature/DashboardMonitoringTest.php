<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Horizon\Horizon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_viewer_dashboard_is_forbidden_for_guests(): void
    {
        $response = $this->get('/log-viewer');

        $response->assertStatus(403);
    }

    public function test_log_viewer_dashboard_is_accessible_by_super_admin(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/log-viewer');

        $response->assertStatus(200);
    }

    public function test_log_viewer_folders_api_uses_the_session_for_same_origin_requests(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)
            ->withHeader('Referer', 'http://whms-web.test/log-viewer')
            ->getJson('/log-viewer/api/folders?direction=desc');

        $response->assertOk();
    }

    public function test_horizon_dashboard_is_forbidden_by_default_in_non_local_environment(): void
    {
        $response = $this->get('/horizon');

        $response->assertStatus(403);
    }

    public function test_horizon_dashboard_is_accessible_when_authorized(): void
    {
        Horizon::auth(fn () => true);

        $response = $this->get('/horizon');

        $response->assertStatus(200);
    }

    public function test_filament_admin_login_page_is_accessible(): void
    {
        $response = $this->get('/admin/login');

        $response->assertStatus(200);
    }

    public function test_filament_shield_roles_page_redirects_guest_to_login(): void
    {
        $response = $this->get('/admin/shield/roles');

        $response->assertRedirect('/admin/login');
    }

    public function test_super_admin_can_access_shield_roles_page(): void
    {
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'ViewAny:Role', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        $response = $this->actingAs($user)->get('/admin/shield/roles');

        $response->assertStatus(200);
    }

    public function test_sample_super_admin_can_authenticate_and_access_admin_dashboard(): void
    {
        $this->seed();

        $this->assertTrue(auth()->attempt([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]));

        $user = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertStatus(200);
    }

    public function test_user_management_redirects_guest_to_login(): void
    {
        $response = $this->get('/admin/users');

        $response->assertRedirect('/admin/login');
    }

    public function test_super_admin_can_access_user_management(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin/users');

        $response->assertStatus(200);
    }

    public function test_super_admin_can_access_create_user_page(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin/users/create');

        $response->assertStatus(200);
    }

    public function test_panel_user_without_permission_cannot_access_user_management(): void
    {
        $this->seed();

        $user = User::where('email', 'user@example.com')->firstOrFail();

        $response = $this->actingAs($user)->get('/admin/users');

        $response->assertStatus(403);
    }

    public function test_super_admin_can_impersonate_panel_user(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::where('email', 'user@example.com')->firstOrFail();

        $this->assertTrue($admin->canImpersonate());
        $this->assertTrue($user->canBeImpersonated());
        $this->assertFalse($admin->canBeImpersonated());
        $this->assertFalse($user->canImpersonate());
    }

    public function test_user_with_impersonate_permission_can_impersonate(): void
    {
        $this->seed();

        $user = User::where('email', 'user@example.com')->firstOrFail();
        $this->assertFalse($user->canImpersonate());

        $user->givePermissionTo('ImpersonateUser');
        $this->assertTrue($user->fresh()->canImpersonate());
    }
}

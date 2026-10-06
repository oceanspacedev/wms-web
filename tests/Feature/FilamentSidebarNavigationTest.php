<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentSidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_developer_tools_without_duplicate_horizon_and_log_viewer(): void
    {
        $this->seed();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertSeeText('API Documentation');
        $response->assertSeeText('Laravel Horizon');
        $response->assertSeeText('Log Viewer');
        $response->assertDontSee("content: 'Horizon'", false);
        $response->assertDontSee('heroicon-o-document-text', false);
    }

    public function test_panel_user_without_permissions_does_not_see_developer_tools(): void
    {
        $this->seed();

        $user = User::where('email', 'user@example.com')->firstOrFail();

        $response = $this->actingAs($user)->get('/admin');

        $response->assertOk();
        $response->assertDontSeeText('API Documentation');
        $response->assertDontSeeText('Laravel Horizon');
        $response->assertDontSeeText('Log Viewer');
        $response->assertDontSee("content: 'Horizon'", false);
    }
}

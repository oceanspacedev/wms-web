<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScrambleDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_documentation_ui_is_accessible(): void
    {
        $response = $this->get('/docs/api');

        $response->assertStatus(200);
    }

    public function test_api_documentation_json_is_accessible(): void
    {
        $response = $this->get('/docs/api.json');

        $response->assertStatus(200)
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('info.title', 'WHMS API Documentation');
    }

    public function test_user_with_permission_can_access_docs_in_production(): void
    {
        $this->app['env'] = 'production';

        $permission = Permission::firstOrCreate(['name' => 'ViewApiDocs', 'guard_name' => 'web']);
        $role = Role::findOrCreate('api_viewer', 'web');
        $role->givePermissionTo($permission);

        $allowedUser = User::factory()->create();
        $allowedUser->assignRole($role);

        $deniedUser = User::factory()->create();

        $this->actingAs($allowedUser)
            ->get('/docs/api')
            ->assertOk();

        $this->actingAs($deniedUser)
            ->get('/docs/api')
            ->assertForbidden();
    }
}

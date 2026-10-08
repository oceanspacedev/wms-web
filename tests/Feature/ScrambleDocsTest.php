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

        $response->assertStatus(200)
            ->assertDontSee('JR001');
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

    public function test_openapi_describes_canonical_data_access_without_alias_duplicates(): void
    {
        $response = $this->getJson('/docs/api.json');

        $response->assertOk();
        $response->assertJsonMissingPath('paths./user');
        $response->assertJsonMissingPath('paths./user/photo');
        $response->assertJsonPath('paths./login.post.summary', 'Login dengan username atau email');
        $response->assertJsonPath('paths./me.get.summary', 'Ambil profil pengguna yang sedang masuk');
        $this->assertNotEmpty($response->json('paths./me.get.description'));
        $this->assertNotEmpty($response->json('components.schemas.AuthUserResource.properties.name.description'));
        $this->assertNotEmpty($response->json('components.schemas.TrackingOrderResource.properties.no_sj.description'));
        $this->assertNotEmpty($response->json('components.schemas.TrackingOrderResource.properties.latitude.description'));
        $this->assertNotEmpty($response->json('components.schemas.TrackingOrderResource.properties.longitude.description'));
        $this->assertNotEmpty($response->json('components.schemas.PurchaseOrderResource.properties.no_po.description'));

        $photoSchema = json_encode($response->json('components.schemas.UpdateUserProfilePhotoRequest') ?? []);
        $this->assertStringContainsString('avatar_url', (string) $photoSchema);
        $this->assertStringNotContainsString('profile_photo', (string) $photoSchema);
    }
}

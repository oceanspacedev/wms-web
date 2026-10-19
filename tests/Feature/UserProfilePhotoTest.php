<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_implements_has_avatar_interface(): void
    {
        $user = new User;

        $this->assertInstanceOf(HasAvatar::class, $user);
    }

    public function test_user_returns_correct_filament_avatar_url(): void
    {
        $userWithoutAvatar = User::factory()->create(['avatar_url' => null]);
        $this->assertNull($userWithoutAvatar->getFilamentAvatarUrl());

        $userWithLocalAvatar = User::factory()->create(['avatar_url' => 'avatars/profile.png']);
        $this->assertSame(
            rtrim(config('app.url'), '/').'/storage/avatars/profile.png',
            $userWithLocalAvatar->getFilamentAvatarUrl(),
        );

        $userWithExternalAvatar = User::factory()->create(['avatar_url' => 'https://example.com/avatar.png']);
        $this->assertSame('https://example.com/avatar.png', $userWithExternalAvatar->getFilamentAvatarUrl());
    }

    public function test_user_allows_avatar_url_mass_assignment(): void
    {
        $user = User::factory()->create([
            'avatar_url' => 'avatars/my-photo.jpg',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'avatar_url' => 'avatars/my-photo.jpg',
        ]);

        $user->update(['avatar_url' => 'avatars/updated-photo.jpg']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'avatar_url' => 'avatars/updated-photo.jpg',
        ]);
    }

    public function test_user_create_page_contains_avatar_url_field(): void
    {
        $this->actingAs($this->userAdmin());

        Livewire::test(CreateUser::class)
            ->assertFormFieldExists('avatar_url');
    }

    public function test_users_table_contains_avatar_url_column(): void
    {
        $this->actingAs($this->userAdmin());

        Livewire::test(ListUsers::class)
            ->assertTableColumnExists('avatar_url');
    }

    public function test_edit_profile_page_contains_avatar_url_field(): void
    {
        $user = $this->userAdmin();
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->assertFormFieldExists('avatar_url');
    }

    public function test_user_can_save_avatar_on_profile_page(): void
    {
        Storage::fake();
        $user = $this->userAdmin();
        $this->actingAs($user);

        $file = UploadedFile::fake()->image('avatar.jpg');

        Livewire::test(EditProfile::class)
            ->fillForm([
                'avatar_url' => [$file],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertNotNull($user->avatar_url);
        Storage::assertExists($user->avatar_url);
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

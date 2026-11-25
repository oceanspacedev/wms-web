<?php

namespace Database\Seeders;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Artisan::call('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--option' => 'policies_and_permissions',
        ]);

        $superAdminRole = Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
        $superAdminRole->syncPermissions(Permission::all());

        $panelUserRole = Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web']);

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Super Admin',
                'username' => 'admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (blank($admin->username)) {
            $admin->forceFill(['username' => 'admin'])->save();
        }
        $admin->syncRoles([$superAdminRole]);

        $user = User::firstOrCreate(
            ['email' => 'user@example.com'],
            [
                'name' => 'Panel User',
                'username' => 'user',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        if (blank($user->username)) {
            $user->forceFill(['username' => 'user'])->save();
        }
        $user->syncRoles([$panelUserRole]);

        $this->call([
            WarehouseMappingSeeder::class,
            BrandDosTargetSeeder::class,
            ExpeditionSeeder::class,
            ExpeditionRateCardSeeder::class,
            TrackingOrderSeeder::class,
            PurchaseOrderSeeder::class,
        ]);
    }
}

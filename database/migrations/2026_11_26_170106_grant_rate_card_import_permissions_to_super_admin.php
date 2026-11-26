<?php

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const PERMISSIONS = [
        'ViewAny:RateCardImport',
        'View:RateCardImport',
        'Create:RateCardImport',
        'Update:RateCardImport',
        'Delete:RateCardImport',
        'DeleteAny:RateCardImport',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [];
        foreach (self::PERMISSIONS as $name) {
            $permissions[$name] = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $superAdmin = Role::where('name', Utils::getSuperAdminName())
            ->where('guard_name', 'web')
            ->first();

        if ($superAdmin) {
            $superAdmin->givePermissionTo(array_values($permissions));
        }

        Role::where('guard_name', 'web')
            ->whereHas('permissions', fn ($query) => $query->where('name', 'Create:ExpeditionRateCard'))
            ->get()
            ->each(function (Role $role) use ($permissions): void {
                $role->givePermissionTo([
                    $permissions['ViewAny:RateCardImport'],
                    $permissions['Create:RateCardImport'],
                ]);
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Biarkan permission tetap ada agar perubahan role manual tidak terhapus.
    }
};

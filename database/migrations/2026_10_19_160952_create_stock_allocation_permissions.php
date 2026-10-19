<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Izin Shield untuk menu Alokasi Stok (format "Aksi:Model").
     */
    private const ACTIONS = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
        'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::ACTIONS)->map(fn (string $action): Permission => Permission::firstOrCreate([
            'name' => "{$action}:StockAllocation",
            'guard_name' => 'web',
        ]));

        // Super admin di sini tidak lewat Gate (define_via_gate = false), jadi izinnya harus diberikan langsung.
        Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first()?->givePermissionTo($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'like', '%:StockAllocation')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

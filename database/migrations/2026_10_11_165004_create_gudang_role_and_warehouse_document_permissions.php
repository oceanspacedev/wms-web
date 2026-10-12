<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Izin Shield untuk resource Dokumen Gudang (format "Aksi:Model").
     */
    private const ACTIONS = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
        'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder',
    ];

    /**
     * Petugas gudang hanya melihat dan mengunggah dokumen.
     */
    private const GUDANG_ACTIONS = ['ViewAny', 'View', 'Create'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::ACTIONS)->map(fn (string $action): Permission => Permission::firstOrCreate([
            'name' => "{$action}:WarehouseDocument",
            'guard_name' => 'web',
        ]));

        // Super admin di sini tidak lewat Gate (define_via_gate = false), jadi izinnya harus diberikan langsung.
        // Role-nya sendiri dikelola Shield, jadi tidak dibuat di sini.
        Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first()?->givePermissionTo($permissions);

        Role::firstOrCreate(['name' => 'gudang', 'guard_name' => 'web'])->givePermissionTo(
            collect(self::GUDANG_ACTIONS)->map(fn (string $action): string => "{$action}:WarehouseDocument")
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Role::query()->where('name', 'gudang')->where('guard_name', 'web')->delete();
        Permission::query()->where('name', 'like', '%:WarehouseDocument')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

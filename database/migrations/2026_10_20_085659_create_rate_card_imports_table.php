<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Izin Shield untuk menu Import Tarif Kontrak (format "Aksi:Model").
     */
    private const ACTIONS = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
        'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder',
    ];

    /**
     * Riwayat upload pricelist Tarif Kontrak; file diproses di antrean seperti Import Penjualan CSA.
     */
    public function up(): void
    {
        Schema::create('rate_card_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mode', 10)->default('upsert');
            $table->decimal('default_insurance_percent', 5, 2)->default(0.2);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('total_unique')->default(0);
            $table->unsignedInteger('total_created')->default(0);
            $table->unsignedInteger('total_updated')->default(0);
            $table->unsignedInteger('total_new_expeditions')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::ACTIONS)->map(fn (string $action): Permission => Permission::firstOrCreate([
            'name' => "{$action}:RateCardImport",
            'guard_name' => 'web',
        ]));

        // Super admin di sini tidak lewat Gate (define_via_gate = false), jadi izinnya harus diberikan langsung
        Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first()?->givePermissionTo($permissions);

        // Role yang sudah boleh menambah Tarif Kontrak tetap bisa import seperti sebelumnya
        Role::query()
            ->where('guard_name', 'web')
            ->where('name', '!=', 'super_admin')
            ->whereHas('permissions', fn ($query) => $query->where('name', 'Create:ExpeditionRateCard'))
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(['ViewAny:RateCardImport', 'View:RateCardImport', 'Create:RateCardImport', 'Update:RateCardImport']));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'like', '%:RateCardImport')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::dropIfExists('rate_card_imports');
    }
};

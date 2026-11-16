<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Izin Shield untuk menu Master Depo (format "Aksi:Model").
     */
    private const ACTIONS = [
        'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
        'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder',
    ];

    public function up(): void
    {
        // Master depo: kode gudang + nama, bisa ditambah & diedit dari menu Master Depo
        Schema::create('depos', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 20)->unique();
            $table->string('nama');
            $table->timestamps();
        });

        // Depo tempat akun bekerja; nama/kode depo yang diedit langsung terbaca di akun
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('depo_id')->nullable()->after('cabang')->constrained('depos')->nullOnDelete();
        });

        // Depo kurir saat SJ dicatat; disimpan di SJ supaya tidak berubah kalau kurirnya pindah depo
        Schema::table('tracking_orders', function (Blueprint $table) {
            $table->foreignId('depo_id')->nullable()->after('nama_pengirim')->constrained('depos')->nullOnDelete();
        });

        $this->seedDepos();
        $this->grantPermissions();
    }

    public function down(): void
    {
        Schema::table('tracking_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('depo_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('depo_id');
        });

        Schema::dropIfExists('depos');

        Permission::query()->where('name', 'like', '%:Depo')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Isi awal dari gudang MSI di Pemetaan Gudang (kode diawali GM) + Jogja yang belum ada di sana.
     * Database kosong (tes) tidak diisi.
     */
    private function seedDepos(): void
    {
        $warehouses = DB::table('warehouse_mappings')->where('csa_code', 'like', 'GM%')->get(['csa_code', 'csa_name']);

        if ($warehouses->isEmpty()) {
            return;
        }

        $depos = $warehouses
            ->map(fn (object $warehouse): array => [
                'kode' => $warehouse->csa_code,
                // "GUDANG MSIS SEMARANG" → "SEMARANG"
                'nama' => trim((string) preg_replace('/^(GUDANG\s+)?(MSIS?\s+)?/', '', strtoupper(trim((string) $warehouse->csa_name)))) ?: $warehouse->csa_code,
            ])
            ->push(['kode' => 'GMJOG', 'nama' => 'JOGJA'])
            ->unique('kode')
            ->map(fn (array $depo): array => [...$depo, 'created_at' => now(), 'updated_at' => now()])
            ->values()
            ->all();

        DB::table('depos')->insert($depos);
    }

    private function grantPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::ACTIONS)->map(fn (string $action): Permission => Permission::firstOrCreate([
            'name' => "{$action}:Depo",
            'guard_name' => 'web',
        ]));

        // Super admin di sini tidak lewat Gate (define_via_gate = false), jadi izinnya harus diberikan langsung.
        Role::query()->where('name', 'super_admin')->where('guard_name', 'web')->first()?->givePermissionTo($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

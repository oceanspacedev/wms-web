<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Region awal gudang MSI untuk Alokasi Stok. PUSAT terhubung ke semua region; gudang tanpa region
     * (online, retur, sales, dll.) tidak ikut dihitung. Bisa diubah di menu Pemetaan Gudang.
     *
     * @var array<string, string>
     */
    private const REGIONS = [
        'GMJKT' => 'PUSAT',
        'GMBDG' => 'JAWA BARAT',
        'GMCRB' => 'JAWA BARAT',
        'GMSMG' => 'JAWA TENGAH',
        'GMPWT' => 'JAWA TENGAH',
        'GMSBY' => 'JAWA TIMUR',
        'GMMDN' => 'SUMATERA UTARA & TENGAH',
        'GMPKU' => 'SUMATERA UTARA & TENGAH',
        'GMPDG' => 'SUMATERA UTARA & TENGAH',
        'GMJMB' => 'SUMATERA SELATAN',
        'GMPLB' => 'SUMATERA SELATAN',
        'GMBKL' => 'SUMATERA SELATAN',
        'GMLMP' => 'SUMATERA SELATAN',
        'GMMKS' => 'SULAWESI',
        'GMMDO' => 'SULAWESI',
        'GMPLU' => 'SULAWESI',
    ];

    public function up(): void
    {
        Schema::table('warehouse_mappings', function (Blueprint $table) {
            $table->string('region', 50)->nullable()->after('target_sheet');
        });

        foreach (self::REGIONS as $code => $region) {
            DB::table('warehouse_mappings')->where('csa_code', $code)->update(['region' => $region]);
        }

        // Periode laporan penjualan ("Tanggal : 01/10/2026 sd 27/10/2026") untuk menghitung laku per hari
        Schema::table('csa_imports', function (Blueprint $table) {
            $table->date('period_start')->nullable()->after('report_type');
            $table->date('period_end')->nullable()->after('period_start');
        });

        // Sumber hitungan saat alokasi dari laporan stok ERP: posisi stok & laporan penjualan yang dipakai
        Schema::table('stock_allocations', function (Blueprint $table) {
            $table->date('stock_date')->nullable()->after('file_name');
            $table->foreignId('sales_import_id')->nullable()->after('stock_date')->constrained('csa_imports')->nullOnDelete();
            $table->date('sales_start')->nullable()->after('sales_import_id');
            $table->date('sales_end')->nullable()->after('sales_start');
        });
    }

    public function down(): void
    {
        Schema::table('stock_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_import_id');
            $table->dropColumn(['stock_date', 'sales_start', 'sales_end']);
        });

        Schema::table('csa_imports', function (Blueprint $table) {
            $table->dropColumn(['period_start', 'period_end']);
        });

        Schema::table('warehouse_mappings', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }
};

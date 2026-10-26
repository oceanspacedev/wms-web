<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis laporan yang di-upload lewat "Upload Laporan": penjualan CSA (XRJUAL03) atau realisasi TO (XRSTOK23).
     */
    public function up(): void
    {
        Schema::table('csa_imports', function (Blueprint $table) {
            $table->string('report_type', 20)->default('sales')->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('csa_imports', function (Blueprint $table) {
            $table->dropColumn('report_type');
        });
    }
};

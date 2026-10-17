<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('csa_imports', function (Blueprint $table) {
            // SJ yang dilewati karena sudah ada di data hasil import spreadsheet (No SJ + total sama)
            $table->unsignedInteger('total_skipped')->default(0)->after('total_updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('csa_imports', function (Blueprint $table) {
            $table->dropColumn('total_skipped');
        });
    }
};

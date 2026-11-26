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
        Schema::table('csa_shipments', function (Blueprint $table) {
            $table->index('badan_usaha');
            $table->index('created_at');
            $table->index(['csa_import_id', 'created_at']);
            $table->index(['source', 'tanggal_kirim']);
            $table->index('tanggal_diterima');
            $table->index('no_resi_awb');
            $table->index(['target_sheet', 'brand']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('csa_shipments', function (Blueprint $table) {
            $table->dropIndex(['badan_usaha']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['csa_import_id', 'created_at']);
            $table->dropIndex(['source', 'tanggal_kirim']);
            $table->dropIndex(['tanggal_diterima']);
            $table->dropIndex(['no_resi_awb']);
            $table->dropIndex(['target_sheet', 'brand']);
        });
    }
};

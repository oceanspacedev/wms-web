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
            // csa = dari upload laporan CSA, manual = diinput admin (retur, transfer internal, dll.),
            // spreadsheet = hasil import file Google Sheet "Update Pengiriman"
            $table->string('source')->default('csa')->after('csa_import_id')->index();
        });

        // Upload ulang memperbarui SJ yang sama, jadi satu SJ bisa berpindah ke upload terbaru.
        // Menghapus satu upload tidak boleh ikut menghapus data pengiriman yang sudah dilengkapi admin.
        Schema::table('csa_shipments', function (Blueprint $table) {
            $table->dropForeign(['csa_import_id']);
        });

        Schema::table('csa_shipments', function (Blueprint $table) {
            $table->foreignId('csa_import_id')->nullable()->change();
            $table->foreign('csa_import_id')->references('id')->on('csa_imports')->nullOnDelete();
        });

        Schema::table('csa_imports', function (Blueprint $table) {
            $table->unsignedInteger('total_new')->default(0)->after('total_shipments');
            $table->unsignedInteger('total_updated')->default(0)->after('total_new');
        });

        Schema::table('users', function (Blueprint $table) {
            // Admin cabang hanya melihat & mengisi Data Pengiriman cabang ini (kosong = semua cabang)
            $table->string('cabang')->nullable()->after('kode_gudang_arsip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cabang');
        });

        Schema::table('csa_imports', function (Blueprint $table) {
            $table->dropColumn(['total_new', 'total_updated']);
        });

        Schema::table('csa_shipments', function (Blueprint $table) {
            $table->dropForeign(['csa_import_id']);
        });

        Schema::table('csa_shipments', function (Blueprint $table) {
            $table->foreignId('csa_import_id')->nullable(false)->change();
            $table->foreign('csa_import_id')->references('id')->on('csa_imports')->cascadeOnDelete();
            $table->dropColumn('source');
        });
    }
};

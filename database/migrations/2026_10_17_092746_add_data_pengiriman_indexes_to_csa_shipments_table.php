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
            // Urutan default Data Pengiriman (tanggal kirim terbaru), per cabang maupun semua cabang
            $table->index(['target_sheet', 'tanggal_kirim']);
            $table->index('tanggal_kirim');
            // Pilihan filter Brand (DISTINCT) ikut dihitung setiap tabel dirender
            $table->index('brand');
            // Pilihan dropdown di tabel untuk cabang tanpa daftar dropdown sendiri (nilai yang sudah dipakai cabang itu)
            $table->index(['target_sheet', 'nama_ekspedisi']);
            $table->index(['target_sheet', 'ketentuan_biaya_kirim']);
            $table->index(['target_sheet', 'status_pembayaran']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('csa_shipments', function (Blueprint $table) {
            $table->dropIndex(['target_sheet', 'tanggal_kirim']);
            $table->dropIndex(['tanggal_kirim']);
            $table->dropIndex(['brand']);
            $table->dropIndex(['target_sheet', 'nama_ekspedisi']);
            $table->dropIndex(['target_sheet', 'ketentuan_biaya_kirim']);
            $table->dropIndex(['target_sheet', 'status_pembayaran']);
        });
    }
};

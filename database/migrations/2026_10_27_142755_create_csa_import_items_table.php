<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penjualan per gudang per barang (satu kode barang = satu model + warna) dari laporan penjualan CSA yang di-upload.
     */
    public function up(): void
    {
        Schema::create('csa_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('csa_import_id')->constrained()->cascadeOnDelete();
            $table->string('kode_gudang', 50);
            $table->string('nama_gudang')->nullable();
            $table->string('kode_barang', 100);
            $table->string('nama_barang');
            $table->string('model');
            $table->string('warna', 100)->nullable();
            $table->string('brand', 100)->nullable();
            $table->boolean('is_unit')->default(false);
            $table->integer('qty')->default(0);
            $table->decimal('nominal', 18, 2)->default(0);

            $table->index(['csa_import_id', 'kode_gudang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csa_import_items');
    }
};

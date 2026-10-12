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
        Schema::table('users', function (Blueprint $table) {
            // Kode gudang di SCM Arsip (misal GDG-BDG-01) untuk akun petugas gudang
            $table->string('kode_gudang_arsip')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['kode_gudang_arsip']);
            $table->dropColumn('kode_gudang_arsip');
        });
    }
};

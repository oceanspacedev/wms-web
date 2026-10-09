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
            // True jika data yang sama (Nomor SJ + total nominal) sudah ada di sheet sehingga dilewati, bukan ditulis ulang
            $table->boolean('already_in_sheet')->default(false)->after('is_synced');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('csa_shipments', function (Blueprint $table) {
            $table->dropColumn('already_in_sheet');
        });
    }
};

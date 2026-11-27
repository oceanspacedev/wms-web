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
        Schema::table('csa_shipments', function (Blueprint $table): void {
            $table->index('tujuan_dealer');
            $table->index(['target_sheet', 'tujuan_dealer']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('csa_shipments', function (Blueprint $table): void {
            $table->dropIndex(['tujuan_dealer']);
            $table->dropIndex(['target_sheet', 'tujuan_dealer']);
        });
    }
};

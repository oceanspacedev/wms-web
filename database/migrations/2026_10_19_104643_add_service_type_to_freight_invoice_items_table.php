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
        Schema::table('freight_invoice_items', function (Blueprint $table) {
            // Layanan yang ditagih ekspedisi (DARAT/ECO/UDARA), ikut menentukan tarif kontrak pembanding
            $table->string('service_type')->nullable()->after('destination_city');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('freight_invoice_items', function (Blueprint $table) {
            $table->dropColumn('service_type');
        });
    }
};

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
        Schema::table('freight_invoices', function (Blueprint $table) {
            // Kota asal pengiriman untuk mencari Tarif Kontrak (invoice ekspedisi umumnya tidak mencantumkannya)
            $table->string('origin_city')->nullable()->after('invoice_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('freight_invoices', function (Blueprint $table) {
            $table->dropColumn('origin_city');
        });
    }
};

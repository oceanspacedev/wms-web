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
            $table->string('shipment_match_status', 32)->default('unmatched')->after('audit_status');
            $table->string('shipment_matched_by', 16)->nullable()->after('shipment_match_status');
            $table->string('shipment_badan_usaha')->nullable()->after('no_sj');
            $table->string('shipment_reff_note')->nullable()->after('shipment_badan_usaha');
            $table->string('shipment_brand')->nullable()->after('shipment_reff_note');
            $table->decimal('shipment_nominal_sj', 15, 2)->default(0)->after('shipment_brand');
            $table->decimal('shipment_weight', 10, 2)->nullable()->after('actual_weight');
            $table->index('shipment_match_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('freight_invoice_items', function (Blueprint $table) {
            $table->dropIndex(['shipment_match_status']);
            $table->dropColumn([
                'shipment_match_status',
                'shipment_matched_by',
                'shipment_badan_usaha',
                'shipment_reff_note',
                'shipment_brand',
                'shipment_nominal_sj',
                'shipment_weight',
            ]);
        });
    }
};

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
        Schema::table('tracking_orders', function (Blueprint $table) {
            $table->dropForeign(['csa_shipment_id']);
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'csa_shipment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_orders', function (Blueprint $table) {
            $table->string('status')->default('DELIVERED')->index()->after('longitude');
            $table->foreignId('csa_shipment_id')->nullable()->after('status')->constrained('csa_shipments')->nullOnDelete();
        });
    }
};

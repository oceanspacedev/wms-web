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
        Schema::table('stock_allocations', function (Blueprint $table) {
            $table->boolean('use_brand_targets')->default(true)->after('target_dos');
        });

        Schema::table('stock_allocation_lines', function (Blueprint $table) {
            $table->string('brand', 100)->nullable()->after('sku')->index();
            $table->unsignedSmallInteger('target_dos')->nullable()->after('sales_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_allocation_lines', function (Blueprint $table) {
            $table->dropIndex(['brand']);
            $table->dropColumn(['brand', 'target_dos']);
        });

        Schema::table('stock_allocations', function (Blueprint $table) {
            $table->dropColumn('use_brand_targets');
        });
    }
};

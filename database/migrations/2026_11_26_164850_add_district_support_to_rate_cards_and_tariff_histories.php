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
        Schema::table('expedition_rate_cards', function (Blueprint $table): void {
            $table->index(['is_active', 'origin_depo', 'destination_district', 'service_type'], 'rate_card_district_lookup_index');
        });

        Schema::table('tariff_search_histories', function (Blueprint $table): void {
            $table->string('district', 120)->nullable()->after('destination');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expedition_rate_cards', function (Blueprint $table): void {
            $table->dropIndex('rate_card_district_lookup_index');
        });

        Schema::table('tariff_search_histories', function (Blueprint $table): void {
            $table->dropColumn('district');
        });
    }
};

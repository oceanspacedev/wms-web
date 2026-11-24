<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expedition_rate_cards', function (Blueprint $table): void {
            $table->string('pricing_type', 20)->default('per_kg')->after('min_kg');
            $table->index(['is_active', 'origin_depo', 'destination_city', 'service_type'], 'rate_card_route_lookup_index');
        });

        // Backfill data lama yang sebelumnya menumpangkan tarif non-per-kg ke min_kg = 0
        $expeditionIds = DB::table('expeditions')
            ->whereIn('name', [
                'KARYA MANDIRI',
                'IMELDA EXPRES',
                'AIRA CAHAYA ABADI',
                'JASTIP ERIN',
                'SINAR PAPUA',
                'J&T CARGO',
            ])
            ->pluck('id', 'name');

        $collyIds = collect(['KARYA MANDIRI', 'IMELDA EXPRES', 'AIRA CAHAYA ABADI', 'JASTIP ERIN'])
            ->map(fn (string $name): ?int => isset($expeditionIds[$name]) ? (int) $expeditionIds[$name] : null)
            ->filter()
            ->values()
            ->all();

        if ($collyIds !== []) {
            DB::table('expedition_rate_cards')
                ->whereIn('expedition_id', $collyIds)
                ->where('min_kg', 0)
                ->update(['pricing_type' => 'per_colly']);
        }

        if (isset($expeditionIds['SINAR PAPUA'])) {
            DB::table('expedition_rate_cards')
                ->where('expedition_id', (int) $expeditionIds['SINAR PAPUA'])
                ->where('min_kg', 0)
                ->update(['pricing_type' => 'kubikasi']);
        }

        if (isset($expeditionIds['J&T CARGO'])) {
            DB::table('expedition_rate_cards')
                ->where('expedition_id', (int) $expeditionIds['J&T CARGO'])
                ->where('min_kg', 0)
                ->where('notes', 'LTL')
                ->update(['pricing_type' => 'flat']);
        }
    }

    public function down(): void
    {
        Schema::table('expedition_rate_cards', function (Blueprint $table): void {
            $table->dropIndex('rate_card_route_lookup_index');
            $table->dropColumn('pricing_type');
        });
    }
};

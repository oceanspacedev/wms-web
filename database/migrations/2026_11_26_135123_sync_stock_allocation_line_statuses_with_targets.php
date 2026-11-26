<?php

use App\Models\StockAllocationLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        StockAllocationLine::query()
            ->with('allocation:id,target_dos')
            ->select(['id', 'stock_allocation_id', 'dos_before', 'target_dos', 'need_qty', 'status'])
            ->chunkById(500, function ($lines): void {
                foreach ($lines as $line) {
                    $target = $line->effectiveTargetDos($line->allocation?->target_dos);
                    $nextStatus = StockAllocationLine::classifyStatus(
                        $line->dos_before,
                        $target,
                        (int) $line->need_qty,
                    );

                    if ($nextStatus !== $line->status) {
                        DB::table('stock_allocation_lines')
                            ->where('id', $line->id)
                            ->update(['status' => $nextStatus]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data sync: tidak perlu mengembalikan klasifikasi lama.
    }
};

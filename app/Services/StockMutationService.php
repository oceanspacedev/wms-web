<?php

namespace App\Services;

use App\Models\StockAllocation;
use App\Models\StockAllocationTransfer;
use App\Models\StockMutation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dokumen mutasi antar gudang: dibuat dari saran Alokasi Stok yang disetujui, lalu Dikirim → Diterima.
 */
class StockMutationService
{
    /**
     * Saran yang disetujui dan belum masuk dokumen, dikelompokkan per rute (region, dari, ke).
     *
     * @return Collection<int, StockMutation>
     */
    public function createFromApproved(StockAllocation $allocation, ?int $userId): Collection
    {
        return DB::transaction(function () use ($allocation, $userId): Collection {
            $transfers = $allocation->transfers()
                ->where('status', StockAllocationTransfer::STATUS_APPROVED)
                ->whereNull('stock_mutation_id')
                ->orderBy('product_name')
                ->lockForUpdate()
                ->get();

            return $transfers
                ->groupBy(fn (StockAllocationTransfer $transfer): string => $transfer->region.'|'.$transfer->from_warehouse.'|'.$transfer->to_warehouse)
                ->map(function (Collection $group) use ($allocation, $userId): StockMutation {
                    $first = $group->first();

                    $mutation = StockMutation::create([
                        'number' => $this->nextNumber(),
                        'stock_allocation_id' => $allocation->id,
                        'region' => $first->region,
                        'from_warehouse' => $first->from_warehouse,
                        'to_warehouse' => $first->to_warehouse,
                        'status' => StockMutation::STATUS_PREPARED,
                        'created_by' => $userId,
                    ]);

                    $mutation->items()->createMany($group->map(fn (StockAllocationTransfer $transfer): array => [
                        'stock_allocation_transfer_id' => $transfer->id,
                        'sku' => $transfer->sku,
                        'product_name' => $transfer->product_name,
                        'qty' => $transfer->qty,
                    ])->all());

                    StockAllocationTransfer::whereKey($group->modelKeys())->update(['stock_mutation_id' => $mutation->id]);

                    return $mutation;
                })
                ->values();
        });
    }

    public function ship(StockMutation $mutation, ?string $via, ?int $userId): void
    {
        $this->ensureStatus($mutation, StockMutation::STATUS_PREPARED);

        $mutation->update([
            'status' => StockMutation::STATUS_SHIPPED,
            'shipped_at' => now(),
            'shipped_via' => $via,
            'shipped_by' => $userId,
        ]);
    }

    /**
     * @param  array<int, int>  $receivedQty  qty diterima per id item dokumen
     */
    public function receive(StockMutation $mutation, array $receivedQty, ?string $note, ?int $userId): void
    {
        $this->ensureStatus($mutation, StockMutation::STATUS_SHIPPED);

        DB::transaction(function () use ($mutation, $receivedQty, $note, $userId): void {
            foreach ($mutation->items as $item) {
                $item->update(['received_qty' => max(0, (int) ($receivedQty[$item->id] ?? $item->qty))]);
            }

            $mutation->update([
                'status' => StockMutation::STATUS_RECEIVED,
                'received_at' => now(),
                'received_note' => $note,
                'received_by' => $userId,
            ]);
        });
    }

    /**
     * Dokumen yang belum dikirim boleh dibatalkan; sarannya kembali bisa diubah.
     */
    public function cancel(StockMutation $mutation): void
    {
        $this->ensureStatus($mutation, StockMutation::STATUS_PREPARED);

        DB::transaction(function () use ($mutation): void {
            $mutation->transfers()->update(['stock_mutation_id' => null]);
            $mutation->delete();
        });
    }

    /**
     * MUT-2610-001: tahun & bulan dibuat, lalu nomor urut di bulan itu.
     */
    private function nextNumber(): string
    {
        $prefix = 'MUT-'.now()->format('ym').'-';
        $last = StockMutation::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 3, '0', STR_PAD_LEFT);
    }

    private function ensureStatus(StockMutation $mutation, string $status): void
    {
        if ($mutation->status !== $status) {
            throw new RuntimeException('Dokumen '.$mutation->number.' sudah '.strtolower($mutation->statusLabel()).'.');
        }
    }
}

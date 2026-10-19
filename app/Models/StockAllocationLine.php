<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Posisi stok satu SKU di satu gudang: stok, incoming, penjualan, DOS sebelum/sesudah transfer, dan sisa PO.
 */
class StockAllocationLine extends Model
{
    /**
     * Status DOS sebelum transfer (batas mengikuti file DOS: ≤7 kritis, ≤14 waspada, ≤ target aman).
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        'critical' => 'KRITIS',
        'warning' => 'WASPADA',
        'healthy' => 'AMAN',
        'overstock' => 'OVERSTOCK',
        'no_sales' => 'TIDAK ADA PENJUALAN',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUS_COLORS = [
        'critical' => 'danger',
        'warning' => 'warning',
        'healthy' => 'success',
        'overstock' => 'info',
        'no_sales' => 'gray',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'stock_allocation_id',
        'region',
        'warehouse',
        'sku',
        'product_name',
        'stock',
        'incoming',
        'sales_qty',
        'sales_days',
        'avg_daily_sales',
        'dos_before',
        'dos_after',
        'status',
        'need_qty',
        'transfer_in',
        'transfer_out',
        'po_qty',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'incoming' => 'integer',
            'sales_qty' => 'integer',
            'sales_days' => 'integer',
            'avg_daily_sales' => 'float',
            'dos_before' => 'float',
            'dos_after' => 'float',
            'need_qty' => 'integer',
            'transfer_in' => 'integer',
            'transfer_out' => 'integer',
            'po_qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockAllocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(StockAllocation::class, 'stock_allocation_id');
    }

    public function stockAfter(): int
    {
        return $this->stock + $this->transfer_in - $this->transfer_out;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'gray';
    }
}

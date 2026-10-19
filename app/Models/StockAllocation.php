<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kali hitung saran alokasi stok antar gudang dari file stok, penjualan & incoming.
 */
class StockAllocation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'file_name',
        'target_dos',
        'min_transfer_qty',
        'warehouse_count',
        'sku_count',
        'transfer_count',
        'transfer_qty',
        'po_qty_before',
        'po_qty_after',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_dos' => 'integer',
            'min_transfer_qty' => 'integer',
            'warehouse_count' => 'integer',
            'sku_count' => 'integer',
            'transfer_count' => 'integer',
            'transfer_qty' => 'integer',
            'po_qty_before' => 'integer',
            'po_qty_after' => 'integer',
        ];
    }

    /**
     * @return HasMany<StockAllocationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAllocationLine::class);
    }

    /**
     * @return HasMany<StockAllocationTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(StockAllocationTransfer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Persentase kebutuhan PO yang tertutup oleh transfer antar gudang.
     */
    public function poReductionPercent(): int
    {
        return $this->po_qty_before > 0
            ? (int) round(($this->po_qty_before - $this->po_qty_after) / $this->po_qty_before * 100)
            : 0;
    }
}

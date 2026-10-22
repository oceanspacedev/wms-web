<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen mutasi barang dari satu gudang ke gudang lain, dibuat dari saran Alokasi Stok yang disetujui.
 */
class StockMutation extends Model
{
    public const STATUS_PREPARED = 'prepared';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_RECEIVED = 'received';

    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_PREPARED => 'Disiapkan',
        self::STATUS_SHIPPED => 'Dikirim',
        self::STATUS_RECEIVED => 'Diterima',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUS_COLORS = [
        self::STATUS_PREPARED => 'gray',
        self::STATUS_SHIPPED => 'warning',
        self::STATUS_RECEIVED => 'success',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'stock_allocation_id',
        'region',
        'from_warehouse',
        'to_warehouse',
        'status',
        'shipped_at',
        'shipped_via',
        'shipped_by',
        'received_at',
        'received_note',
        'received_by',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockAllocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(StockAllocation::class, 'stock_allocation_id');
    }

    /**
     * @return HasMany<StockMutationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockMutationItem::class);
    }

    /**
     * @return HasMany<StockAllocationTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(StockAllocationTransfer::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * Jumlah unit yang diterima tidak sama dengan yang dikirim (kurang, lebih, atau rusak di jalan).
     */
    public function hasDifference(): bool
    {
        return $this->status === self::STATUS_RECEIVED
            && $this->items->contains(fn (StockMutationItem $item): bool => $item->received_qty !== null && $item->received_qty !== $item->qty);
    }
}

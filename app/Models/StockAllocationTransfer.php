<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saran pindah stok satu SKU dari gudang yang berlebih ke gudang yang kekurangan dalam satu region.
 */
class StockAllocationTransfer extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Belum disetujui',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUS_COLORS = [
        self::STATUS_PENDING => 'gray',
        self::STATUS_APPROVED => 'success',
        self::STATUS_REJECTED => 'danger',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'stock_allocation_id',
        'from_line_id',
        'to_line_id',
        'region',
        'sku',
        'product_name',
        'from_warehouse',
        'to_warehouse',
        'suggested_qty',
        'qty',
        'status',
        'stock_mutation_id',
        'decided_by',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suggested_qty' => 'integer',
            'qty' => 'integer',
            'decided_at' => 'datetime',
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
     * @return BelongsTo<StockAllocationLine, $this>
     */
    public function fromLine(): BelongsTo
    {
        return $this->belongsTo(StockAllocationLine::class, 'from_line_id');
    }

    /**
     * @return BelongsTo<StockAllocationLine, $this>
     */
    public function toLine(): BelongsTo
    {
        return $this->belongsTo(StockAllocationLine::class, 'to_line_id');
    }

    /**
     * @return BelongsTo<StockMutation, $this>
     */
    public function mutation(): BelongsTo
    {
        return $this->belongsTo(StockMutation::class, 'stock_mutation_id');
    }

    /**
     * Sudah masuk dokumen mutasi: keputusan dan qty-nya tidak boleh diubah lagi.
     */
    public function isLocked(): bool
    {
        return $this->stock_mutation_id !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

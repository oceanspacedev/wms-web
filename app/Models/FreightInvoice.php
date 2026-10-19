<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FreightInvoice extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'expedition_id',
        'invoice_number',
        'invoice_date',
        'origin_city',
        'period_start',
        'period_end',
        'file_path',
        'total_billed_amount',
        'total_approved_amount',
        'total_discrepancy_amount',
        'total_unverified_amount',
        'total_items_count',
        'matched_count',
        'discrepancy_count',
        'unrecognized_count',
        'duplicate_count',
        'status',
        'notes',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'invoice_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'total_billed_amount' => 'decimal:2',
        'total_approved_amount' => 'decimal:2',
        'total_discrepancy_amount' => 'decimal:2',
        'total_unverified_amount' => 'decimal:2',
        'total_items_count' => 'integer',
        'matched_count' => 'integer',
        'discrepancy_count' => 'integer',
        'unrecognized_count' => 'integer',
        'duplicate_count' => 'integer',
    ];

    /**
     * @return BelongsTo<Expedition, $this>
     */
    public function expedition(): BelongsTo
    {
        return $this->belongsTo(Expedition::class);
    }

    /**
     * @return HasMany<FreightInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FreightInvoiceItem::class);
    }
}

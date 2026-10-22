<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMutationItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'stock_mutation_id',
        'stock_allocation_transfer_id',
        'sku',
        'product_name',
        'qty',
        'received_qty',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'received_qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockMutation, $this>
     */
    public function mutation(): BelongsTo
    {
        return $this->belongsTo(StockMutation::class, 'stock_mutation_id');
    }
}

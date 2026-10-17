<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsaImport extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'file_name',
        'file_path',
        'status',
        'total_raw_rows',
        'total_shipments',
        'total_new',
        'total_updated',
        'total_skipped',
        'total_synced',
        'error_message',
        'summary_by_sheet',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'total_raw_rows' => 'integer',
        'total_shipments' => 'integer',
        'total_new' => 'integer',
        'total_updated' => 'integer',
        'total_skipped' => 'integer',
        'total_synced' => 'integer',
        'summary_by_sheet' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CsaShipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(CsaShipment::class);
    }
}

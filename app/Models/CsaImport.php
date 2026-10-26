<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsaImport extends Model
{
    use HasFactory;

    /** Laporan penjualan CSA [XRJUAL03] atau laporan pengiriman depo. */
    public const REPORT_SALES = 'sales';

    /** Laporan realisasi transfer order [XRSTOK23]: TO dari gudang pusat ke depo. */
    public const REPORT_TRANSFER = 'transfer';

    /**
     * @var array<string, string>
     */
    public const REPORT_LABELS = [
        self::REPORT_SALES => 'Penjualan CSA',
        self::REPORT_TRANSFER => 'Realisasi TO',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'file_name',
        'file_path',
        'report_type',
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

    public function reportLabel(): string
    {
        return self::REPORT_LABELS[$this->report_type] ?? self::REPORT_LABELS[self::REPORT_SALES];
    }
}

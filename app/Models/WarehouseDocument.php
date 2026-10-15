<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Memo/DO yang diunggah petugas gudang di WMS untuk diteruskan ke SCM Arsip.
 */
class WarehouseDocument extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'kode_gudang',
        'arsip_program_id',
        'po_sj_number',
        'program_title',
        'document_type',
        'file_path',
        'original_name',
        'file_size',
        'status',
        'arsip_document_id',
        'error_message',
        'sent_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'file_size' => 'integer',
        'sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WarehouseDocument $document): void {
            $document->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

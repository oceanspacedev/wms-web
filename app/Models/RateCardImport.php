<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Satu kali upload pricelist Tarif Kontrak (Riwayat Import di halaman Tarif Kontrak); diproses di antrean (ProcessRateCardImportJob).
 */
class RateCardImport extends Model
{
    /**
     * @var array<string, string>
     */
    public const MODE_LABELS = [
        'upsert' => 'Tambah & Update',
        'replace' => 'Kosongkan & Ganti Semua',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'file_name',
        'file_path',
        'mode',
        'default_insurance_percent',
        'status',
        'total_rows',
        'total_unique',
        'total_created',
        'total_updated',
        'total_new_expeditions',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_insurance_percent' => 'float',
            'total_rows' => 'integer',
            'total_unique' => 'integer',
            'total_created' => 'integer',
            'total_updated' => 'integer',
            'total_new_expeditions' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // File pricelist bisa ±100 MB; ikut dihapus bersama riwayatnya
        static::deleted(fn (RateCardImport $import) => Storage::disk('local')->delete($import->file_path));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canReprocess(): bool
    {
        return in_array($this->status, ['pending', 'failed'], true) && Storage::disk('local')->exists($this->file_path);
    }
}

<?php

namespace App\Models;

use Database\Factories\DepoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Depo/gudang tempat kurir & admin bekerja (kode gudang + nama). Dipakai di akun dan ikut tercatat
 * di surat jalan yang dikirim kurirnya; nama/kode yang diedit langsung terbaca di keduanya.
 */
class Depo extends Model
{
    /** @use HasFactory<DepoFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kode',
        'nama',
    ];

    protected static function booted(): void
    {
        static::saving(function (Depo $depo): void {
            $depo->kode = Str::upper(Str::squish((string) $depo->kode));
            $depo->nama = Str::upper(Str::squish((string) $depo->nama));
        });

        // Depo yang masih dipakai akun atau surat jalan tidak boleh dihapus
        static::deleting(fn (Depo $depo): bool => ! $depo->isUsed());
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<TrackingOrder, $this>
     */
    public function trackingOrders(): HasMany
    {
        return $this->hasMany(TrackingOrder::class);
    }

    public function isUsed(): bool
    {
        return $this->users()->exists() || $this->trackingOrders()->exists();
    }

    /**
     * "PALEMBANG (GMPLB)" untuk pilihan dropdown.
     */
    public function label(): string
    {
        return "{$this->nama} ({$this->kode})";
    }

    /**
     * Depo dari teks yang diketik di Excel: kode ("GMPLB") atau nama ("Palembang"), tanpa membedakan huruf besar/kecil.
     */
    public static function findByText(?string $text): ?self
    {
        $text = Str::upper(Str::squish((string) $text));

        if ($text === '') {
            return null;
        }

        return self::query()->where('kode', $text)->first()
            ?? self::query()->whereRaw('UPPER(nama) = ?', [$text])->first();
    }
}

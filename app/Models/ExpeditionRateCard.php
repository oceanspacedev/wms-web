<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class ExpeditionRateCard extends Model
{
    use HasFactory;

    public const PRICING_PER_KG = 'per_kg';

    public const PRICING_FLAT = 'flat';

    public const PRICING_PER_COLLY = 'per_colly';

    public const PRICING_KUBIKASI = 'kubikasi';

    public const PRICING_DOC = 'doc';

    /**
     * @var array<string, string>
     */
    public const PRICING_LABELS = [
        self::PRICING_PER_KG => 'Per KG',
        self::PRICING_FLAT => 'Flat',
        self::PRICING_PER_COLLY => 'Per Koli',
        self::PRICING_KUBIKASI => 'Per Kubikasi',
        self::PRICING_DOC => 'Dokumen',
    ];

    /**
     * @var array<string, string>
     */
    public const PRICING_BADGES = [
        self::PRICING_PER_KG => '/KG',
        self::PRICING_FLAT => 'Flat',
        self::PRICING_PER_COLLY => '/Koli',
        self::PRICING_KUBIKASI => '/Kubik',
        self::PRICING_DOC => 'Dokumen',
    ];

    public const ROUTE_CACHE_VERSION_KEY = 'rate_cards:route_cache_version';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'expedition_id',
        'origin_depo',
        'destination_city',
        'destination_district',
        'province',
        'service_type',
        'rate_per_kg',
        'min_kg',
        'pricing_type',
        'insurance_rate_percent',
        'sla_days',
        'notes',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'pricing_type' => self::PRICING_PER_KG,
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'rate_per_kg' => 'decimal:2',
        'min_kg' => 'decimal:2',
        'insurance_rate_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn (): null => self::clearRouteCache());
        static::deleted(fn (): null => self::clearRouteCache());
    }

    /**
     * @return BelongsTo<Expedition, $this>
     */
    public function expedition(): BelongsTo
    {
        return $this->belongsTo(Expedition::class);
    }

    public function isFlatPricing(): bool
    {
        return in_array($this->pricing_type, [
            self::PRICING_FLAT,
            self::PRICING_PER_COLLY,
            self::PRICING_KUBIKASI,
            self::PRICING_DOC,
        ], true);
    }

    /**
     * Hitung ongkir baku berdasarkan jenis tarif: per_kg dikali max(berat, min_kg minimal 1 kg),
     * sedangkan flat/koli/kubikasi/dokumen memakai nilai tarif utuh; hasilnya dikalikan qty pengiriman.
     */
    public function calculateShippingCost(float $weight, int $qty = 1): float
    {
        $rate = (float) $this->rate_per_kg;
        $baseCost = $this->isFlatPricing()
            ? $rate
            : max($weight, max(1.0, (float) $this->min_kg)) * $rate;

        return $baseCost * max(1, $qty);
    }

    public function pricingLabel(): string
    {
        return self::PRICING_LABELS[$this->pricing_type] ?? self::PRICING_LABELS[self::PRICING_PER_KG];
    }

    public function pricingBadge(): string
    {
        return self::PRICING_BADGES[$this->pricing_type] ?? self::PRICING_BADGES[self::PRICING_PER_KG];
    }

    public static function routeCacheVersion(): int
    {
        return (int) Cache::rememberForever(self::ROUTE_CACHE_VERSION_KEY, fn (): int => 1);
    }

    public static function clearRouteCache(): null
    {
        $next = self::routeCacheVersion() + 1;
        Cache::forever(self::ROUTE_CACHE_VERSION_KEY, $next);

        return null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Master Target DOS (Days of Stock) per brand & segmen (online/offline/region).
 */
class BrandDosTarget extends Model
{
    public const SEGMENT_ALL = 'all';

    public const SEGMENT_ONLINE = 'online';

    public const SEGMENT_OFFLINE = 'offline';

    /**
     * @var array<string, string>
     */
    public const SEGMENT_LABELS = [
        self::SEGMENT_ALL => 'Semua Kanal',
        self::SEGMENT_ONLINE => 'Online',
        self::SEGMENT_OFFLINE => 'Offline',
    ];

    /**
     * @var array<string, string>
     */
    public const SEGMENT_COLORS = [
        self::SEGMENT_ALL => 'gray',
        self::SEGMENT_ONLINE => 'info',
        self::SEGMENT_OFFLINE => 'warning',
    ];

    /**
     * Variasi ejaan/singkatan brand di laporan ERP/CSA agar cocok ke nama brand di master.
     *
     * @var array<string, string>
     */
    private const BRAND_ALIASES = [
        'TECHNO' => 'TECNO',
        'VILAON' => 'VILLAON',
        'MOTO' => 'MOTOROLA',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company',
        'brand',
        'label',
        'segment',
        'region_scope',
        'target_dos',
        'is_active',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_dos' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BrandDosTarget $record): void {
            $record->company = filled($record->company) ? strtoupper(trim((string) $record->company)) : null;
            $record->label = strtoupper(trim((string) ($record->label ?: $record->brand)));

            [$inferredBrand, $inferredSegment, $inferredRegion] = self::inferFromLabel($record->label);

            $record->brand = filled($record->brand)
                ? self::normalizeBrand($record->brand)
                : $inferredBrand;

            $record->segment = filled($record->segment) && array_key_exists($record->segment, self::SEGMENT_LABELS)
                ? $record->segment
                : $inferredSegment;

            if ($record->segment === self::SEGMENT_ALL && $inferredSegment !== self::SEGMENT_ALL) {
                $record->segment = $inferredSegment;
            }

            $record->region_scope = filled($record->region_scope)
                ? strtoupper(trim((string) $record->region_scope))
                : $inferredRegion;
        });
    }

    /**
     * Tebak brand induk, kanal (online/offline), dan region (WJ/CJ) langsung dari nama brand di tabel Monitoring DOS.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    public static function inferFromLabel(string $label): array
    {
        $clean = strtoupper(trim((string) preg_replace('/\s+/', ' ', $label)));
        $segment = self::SEGMENT_ALL;
        $region = null;

        if (preg_match('/^(.*?)\s+(ONLINE|OFFLINE)$/', $clean, $match) === 1) {
            $clean = trim($match[1]);
            $segment = $match[2] === 'ONLINE' ? self::SEGMENT_ONLINE : self::SEGMENT_OFFLINE;
        } elseif (preg_match('/^(.*?)\s+(WJ|CJ)$/', $clean, $match) === 1) {
            $clean = trim($match[1]);
            $region = $match[2] === 'WJ' ? 'JAWA BARAT' : 'JAWA TENGAH';
        }

        return [self::normalizeBrand($clean), $segment, $region];
    }

    public static function normalizeBrand(?string $brand): string
    {
        $clean = strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) $brand)));

        return self::BRAND_ALIASES[$clean] ?? $clean;
    }

    public static function isOnlineContext(?string $brand, string $warehouse, string $region): bool
    {
        $wh = strtoupper(trim($warehouse));
        $reg = strtoupper(trim($region));
        $br = strtoupper(trim((string) $brand));

        return str_contains($wh, 'ONL')
            || $wh === 'GBO'
            || str_contains($reg, 'ONLINE')
            || str_contains($br, 'ONLINE');
    }

    /**
     * Cari target DOS paling spesifik untuk satu baris alokasi dari koleksi master aktif (tanpa query tambahan).
     * Urutan prioritas:
     * 1. Cocok persis dengan `label` (mis. "TECNO ONLINE", "REALME WJ").
     * 2. Cocok `brand` + `region_scope` + `segment` spesifik (online/offline).
     * 3. Cocok `brand` + `region_scope` (semua kanal).
     * 4. Cocok `brand` + `segment` spesifik (online/offline, semua region).
     * 5. Cocok `brand` umum (semua kanal & semua region).
     * 6. Fallback ke target DOS default alokasi.
     *
     * @param  Collection<int, BrandDosTarget>  $activeTargets
     */
    public static function resolveFromCollection(
        Collection $activeTargets,
        ?string $brand,
        string $warehouse,
        string $region,
        int $fallbackTarget,
    ): int {
        $rawBrand = strtoupper(trim((string) preg_replace('/\s+/', ' ', (string) $brand)));

        if ($rawBrand === '' || $activeTargets->isEmpty()) {
            return $fallbackTarget;
        }

        $normalizedBrand = self::normalizeBrand($rawBrand);
        $normalizedRegion = strtoupper(trim($region));
        $segment = self::isOnlineContext($rawBrand, $warehouse, $normalizedRegion)
            ? self::SEGMENT_ONLINE
            : self::SEGMENT_OFFLINE;

        $candidates = $activeTargets->filter(
            fn (BrandDosTarget $target): bool => $target->is_active && $target->target_dos !== null
        );

        // 1. Cocok persis label (misal file DOS/ERP langsung menulis "TECNO ONLINE" atau "REALME WJ")
        $exactLabel = $candidates->first(
            fn (BrandDosTarget $target): bool => strtoupper(trim((string) $target->label)) === $rawBrand
        );
        if ($exactLabel !== null) {
            return (int) $exactLabel->target_dos;
        }

        // Ambil baris yang brand-nya cocok (termasuk jika rawBrand diawali nama brand, mis. "TECNO OFFLINE" -> "TECNO")
        $byBrand = $candidates->filter(function (BrandDosTarget $target) use ($normalizedBrand, $rawBrand): bool {
            $targetBrand = self::normalizeBrand($target->brand);

            return $targetBrand === $normalizedBrand
                || $targetBrand === $rawBrand
                || str_starts_with($rawBrand, $targetBrand.' ');
        });

        if ($byBrand->isEmpty()) {
            return $fallbackTarget;
        }

        // 2. Brand + Region + Segment spesifik
        $match = $byBrand->first(fn (BrandDosTarget $t): bool => $t->region_scope === $normalizedRegion && $t->segment === $segment)
            // 3. Brand + Region (semua kanal)
            ?? $byBrand->first(fn (BrandDosTarget $t): bool => $t->region_scope === $normalizedRegion && $t->segment === self::SEGMENT_ALL)
            // 4. Brand + Segment spesifik (tanpa batasan region)
            ?? $byBrand->first(fn (BrandDosTarget $t): bool => $t->region_scope === null && $t->segment === $segment)
            // 5. Brand umum (tanpa batasan region & kanal)
            ?? $byBrand->first(fn (BrandDosTarget $t): bool => $t->region_scope === null && $t->segment === self::SEGMENT_ALL)
            // 6. Jika semua baris brand ini punya region/segment tapi berbeda, pakai offline/all sebagai default jika tersedia
            ?? $byBrand->first(fn (BrandDosTarget $t): bool => $t->segment === self::SEGMENT_ALL);

        return $match !== null ? (int) $match->target_dos : $fallbackTarget;
    }

    public function segmentLabel(): string
    {
        return self::SEGMENT_LABELS[$this->segment] ?? $this->segment;
    }

    public function segmentColor(): string
    {
        return self::SEGMENT_COLORS[$this->segment] ?? 'gray';
    }
}

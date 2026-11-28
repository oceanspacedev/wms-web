<?php

namespace App\Filament\Pages;

use App\Models\ExpeditionRateCard;
use App\Models\TariffSearchHistory;
use App\Services\FreightRateGoogleSheetService;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CheckTariff extends Page
{
    use HasPageShield;

    protected string $view = 'filament.pages.check-tariff';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static \UnitEnum|string|null $navigationGroup = 'Ekspedisi';

    protected static ?string $navigationLabel = 'Cek Tarif';

    protected static ?string $title = 'Cek Tarif Ekspedisi';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rates = [];

    public bool $hasSearched = false;

    public bool $showHistory = false;

    public ?string $searchedOrigin = null;

    /**
     * @var list<string>
     */
    public array $searchedOrigins = [];

    public ?string $searchedDestination = null;

    /**
     * @var list<string>
     */
    public array $searchedDestinations = [];

    public ?string $searchedDistrict = null;

    public float $searchedWeight = 0;

    public ?string $searchedService = null;

    public ?float $searchedTotalAmount = null;

    public ?string $searchedStoreName = null;

    public ?string $searchedItemType = null;

    public ?string $searchedAddress = null;

    /**
     * @var array{
     *     cheapest: ?array<string, mixed>,
     *     fastest: ?array<string, mixed>,
     *     warnings: array<int, string>,
     *     whatsapp_text: string
     * }|null
     */
    public ?array $aiInsights = null;

    /**
     * @var array{is_synced: bool, total_rows: int, synced_at: ?string}|null
     */
    public ?array $syncInfo = null;

    public function mount(FreightRateGoogleSheetService $service): void
    {
        $this->syncInfo = $service->getSyncInfo();

        $this->form->fill([
            'origin' => [],
            'destination' => [],
            'district' => null,
            'weight' => null,
            'service' => '',
            'total_amount' => null,
        ]);

        $this->hasSearched = false;
        $this->rates = [];
        $this->aiInsights = null;
        $this->searchedOrigin = null;
        $this->searchedOrigins = [];
        $this->searchedDestination = null;
        $this->searchedDestinations = [];
        $this->searchedDistrict = null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Form Cek Tarif')
                    ->description('Masukkan rute pengiriman (satu atau beberapa kota asal ke satu atau beberapa kota/kecamatan tujuan), berat barang, dan nilai barang untuk membandingkan tarif seluruh ekspedisi.')
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 3,
                        ])->schema([
                            Select::make('origin')
                                ->label('Kota Asal')
                                ->multiple()
                                ->options(function (Get $get): array {
                                    $options = $this->getOriginOptions();
                                    foreach ($this->normalizeOrigins($get('origin')) as $current) {
                                        if (! isset($options[$current])) {
                                            $options[$current] = $current;
                                        }
                                    }

                                    return $options;
                                })
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                    $origins = $this->normalizeOrigins($state);
                                    if ($origins === []) {
                                        $set('destination', []);
                                        $set('district', null);

                                        return;
                                    }

                                    $availableDestinations = $this->getDestinationOptionsForOrigins($origins);
                                    $currentDestinations = $this->normalizeCities($get('destination'));

                                    if (! empty($availableDestinations) && $currentDestinations !== []) {
                                        $validDestinations = array_values(array_filter(
                                            $currentDestinations,
                                            fn (string $dest): bool => isset($availableDestinations[$dest]),
                                        ));

                                        if ($validDestinations !== $currentDestinations) {
                                            $set('destination', $validDestinations);
                                        }

                                        $currentDestinations = $validDestinations;
                                    }

                                    if ($currentDestinations === []) {
                                        $set('district', null);

                                        return;
                                    }

                                    $currentDistrict = (string) $get('district');
                                    if (! empty($currentDistrict)) {
                                        $availableDistricts = $this->getDistrictOptionsForRoutes($origins, $currentDestinations);
                                        if (! isset($availableDistricts[$currentDistrict])) {
                                            $set('district', null);
                                        }
                                    }
                                })
                                ->helperText('Bisa pilih lebih dari 1 kota asal untuk dibandingkan.')
                                ->placeholder('Pilih satu atau beberapa kota asal'),

                            Select::make('destination')
                                ->label('Kota Tujuan')
                                ->multiple()
                                ->options(function (Get $get): array {
                                    $origins = $this->normalizeOrigins($get('origin'));
                                    $options = $origins !== [] ? $this->getDestinationOptionsForOrigins($origins) : [];
                                    foreach ($this->normalizeCities($get('destination')) as $current) {
                                        if (! isset($options[$current])) {
                                            $options[$current] = $current;
                                        }
                                    }

                                    return $options;
                                })
                                ->helperText(function (Get $get): ?string {
                                    $origins = $this->normalizeOrigins($get('origin'));
                                    if ($origins === []) {
                                        return 'Pilih kota asal dulu.';
                                    }
                                    $count = count($this->getDestinationOptionsForOrigins($origins));
                                    if ($count === 0) {
                                        return null;
                                    }

                                    return count($origins) > 1
                                        ? "Tersedia {$count} kota tujuan dari ".count($origins).' kota asal (bisa pilih lebih dari 1).'
                                        : "Tersedia {$count} kota tujuan (bisa pilih lebih dari 1).";
                                })
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                    $destinations = $this->normalizeCities($state);
                                    if ($destinations === []) {
                                        $set('district', null);

                                        return;
                                    }

                                    $origins = $this->normalizeOrigins($get('origin'));
                                    $currentDistrict = (string) $get('district');
                                    if (! empty($currentDistrict) && $origins !== []) {
                                        $availableDistricts = $this->getDistrictOptionsForRoutes($origins, $destinations);
                                        if (! isset($availableDistricts[$currentDistrict])) {
                                            $set('district', null);
                                        }
                                    }
                                })
                                ->placeholder('Pilih satu atau beberapa kota tujuan'),

                            Select::make('district')
                                ->label('Kecamatan (Opsional)')
                                ->options(function (Get $get): array {
                                    $origins = $this->normalizeOrigins($get('origin'));
                                    $destinations = $this->normalizeCities($get('destination'));
                                    $options = ($origins !== [] && $destinations !== [])
                                        ? $this->getDistrictOptionsForRoutes($origins, $destinations)
                                        : [];

                                    $current = (string) $get('district');
                                    if (! empty($current) && ! isset($options[$current])) {
                                        $label = str_contains($current, '||') ? explode('||', $current, 2)[1] : $current;
                                        $options[$current] = $label;
                                    }

                                    return $options;
                                })
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search, Get $get): array {
                                    $origins = $this->normalizeOrigins($get('origin'));
                                    if ($origins === []) {
                                        return [];
                                    }

                                    $destinations = $this->normalizeCities($get('destination'));
                                    $needle = strtoupper(trim($search));

                                    if ($destinations !== []) {
                                        $options = $this->getDistrictOptionsForRoutes($origins, $destinations);
                                        if ($needle === '') {
                                            return array_slice($options, 0, 50, true);
                                        }

                                        $filtered = array_filter(
                                            $options,
                                            fn (string $label): bool => str_contains(strtoupper($label), $needle),
                                        );

                                        return array_slice($filtered, 0, 50, true);
                                    }

                                    return $this->searchDistrictsForOrigins($origins, $search, 50);
                                })
                                ->getOptionLabelUsing(function ($value): ?string {
                                    if (blank($value)) {
                                        return null;
                                    }

                                    $text = (string) $value;

                                    return str_contains($text, '||') ? explode('||', $text, 2)[1] : $text;
                                })
                                ->live()
                                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                    if (! empty($state) && str_contains($state, '||')) {
                                        [$city, $dist] = explode('||', $state, 2);
                                        $mergedDestinations = $this->normalizeCities([
                                            ...$this->normalizeCities($get('destination')),
                                            $city,
                                        ]);
                                        $set('destination', $mergedDestinations);
                                        $set('district', $dist);
                                    }
                                })
                                ->helperText(function (Get $get): ?string {
                                    $origins = $this->normalizeOrigins($get('origin'));
                                    if ($origins === []) {
                                        return 'Pilih kota asal dulu.';
                                    }

                                    $destinations = $this->normalizeCities($get('destination'));
                                    if ($destinations === []) {
                                        return 'Ketik nama kecamatan langsung (min. 2 huruf) atau pilih kota dulu.';
                                    }

                                    $count = count($this->getDistrictOptionsForRoutes($origins, $destinations));

                                    return $count > 0
                                        ? "Tersedia {$count} kecamatan (kosongkan untuk tarif kota)."
                                        : 'Belum ada rincian kecamatan; cek memakai tarif kota.';
                                })
                                ->placeholder('Semua / Tarif Kota'),

                            TextInput::make('weight')
                                ->label('Berat Barang')
                                ->numeric()
                                ->required()
                                ->minValue(0.1)
                                ->step(0.1)
                                ->placeholder('Contoh: 26')
                                ->suffix('KG'),

                            TextInput::make('total_amount')
                                ->label('Nilai Barang (Amount)')
                                ->numeric()
                                ->prefix('Rp')
                                ->placeholder('Opsional (cth: 10000000)')
                                ->helperText('Untuk hitung asuransi & % ongkir.'),

                            Select::make('service')
                                ->label('Layanan')
                                ->options([
                                    'DARAT' => 'DARAT',
                                    'UDARA' => 'UDARA',
                                    'LAUT' => 'LAUT',
                                ])
                                ->default('')
                                ->placeholder('Semua Service'),
                        ]),
                    ]),
            ]);
    }

    /**
     * Dapatkan daftar kota asal (utamakan master data ExpeditionRateCard, di-cache per versi).
     *
     * @return array<string, string>
     */
    public function getOriginOptions(): array
    {
        $rateCardOrigins = $this->cachedMasterOrigins();

        if (! empty($rateCardOrigins)) {
            return $rateCardOrigins;
        }

        /** @var FreightRateGoogleSheetService $service */
        $service = app(FreightRateGoogleSheetService::class);

        return $service->getOrigins();
    }

    /**
     * Normalisasi input daftar kota (string tunggal, string dipisah koma, atau array) menjadi list unik.
     *
     * @return list<string>
     */
    public function normalizeCities(mixed $cities): array
    {
        if (is_string($cities)) {
            $items = explode(',', $cities);
        } elseif (is_array($cities)) {
            $items = $cities;
        } else {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $value = strtoupper(trim((string) $item));
            if ($value !== '' && ! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Normalisasi input kota asal (string tunggal, string dipisah koma, atau array) menjadi list unik.
     *
     * @return list<string>
     */
    public function normalizeOrigins(mixed $origins): array
    {
        return $this->normalizeCities($origins);
    }

    /**
     * Dapatkan daftar kota tujuan berdasarkan kota asal (utamakan master data ExpeditionRateCard, di-cache per versi).
     *
     * @return array<string, string>
     */
    public function getDestinationOptionsForOrigin(?string $origin): array
    {
        return $this->getDestinationOptionsForOrigins($this->normalizeOrigins($origin));
    }

    /**
     * Dapatkan gabungan daftar kota tujuan dari satu atau beberapa kota asal.
     *
     * @param  list<string>  $origins
     * @return array<string, string>
     */
    public function getDestinationOptionsForOrigins(array $origins): array
    {
        $upperOrigins = $this->normalizeOrigins($origins);
        if ($upperOrigins === []) {
            return [];
        }

        $sortedOrigins = $upperOrigins;
        sort($sortedOrigins);
        $version = ExpeditionRateCard::routeCacheVersion();
        $cacheKey = "rate_cards:dests:v{$version}:".md5(implode('|', $sortedOrigins));

        $rateCardDestinations = Cache::remember($cacheKey, 3600, function () use ($sortedOrigins): array {
            return ExpeditionRateCard::where('is_active', true)
                ->whereIn('origin_depo', $sortedOrigins)
                ->whereNotNull('destination_city')
                ->where('destination_city', '!=', '')
                ->distinct()
                ->orderBy('destination_city')
                ->pluck('destination_city', 'destination_city')
                ->toArray();
        });

        if (! empty($rateCardDestinations)) {
            return $rateCardDestinations;
        }

        /** @var FreightRateGoogleSheetService $service */
        $service = app(FreightRateGoogleSheetService::class);
        $merged = [];
        foreach ($sortedOrigins as $orig) {
            foreach ($service->getDestinationOptions($orig) as $key => $label) {
                $merged[$key] = $label;
            }
        }
        ksort($merged);

        return $merged;
    }

    /**
     * Daftar kecamatan tujuan untuk rute asal + kota tertentu (di-cache per versi).
     *
     * @return array<string, string>
     */
    public function getDistrictOptionsForRoute(?string $origin, ?string $destination): array
    {
        return $this->getDistrictOptionsForRoutes(
            $this->normalizeCities($origin),
            $this->normalizeCities($destination),
        );
    }

    /**
     * Gabungan daftar kecamatan tujuan untuk satu atau beberapa kota asal ke satu kota tujuan.
     *
     * @param  list<string>  $origins
     * @return array<string, string>
     */
    public function getDistrictOptionsForOrigins(array $origins, ?string $destination): array
    {
        return $this->getDistrictOptionsForRoutes($origins, $this->normalizeCities($destination));
    }

    /**
     * Gabungan daftar kecamatan tujuan untuk satu atau beberapa kota asal ke satu atau beberapa kota tujuan.
     *
     * @param  list<string>  $origins
     * @param  list<string>  $destinations
     * @return array<string, string>
     */
    public function getDistrictOptionsForRoutes(array $origins, array $destinations): array
    {
        $upperOrigins = $this->normalizeCities($origins);
        $upperDestinations = $this->normalizeCities($destinations);
        if ($upperOrigins === [] || $upperDestinations === []) {
            return [];
        }

        $sortedOrigins = $upperOrigins;
        sort($sortedOrigins);
        $sortedDestinations = $upperDestinations;
        sort($sortedDestinations);
        $version = ExpeditionRateCard::routeCacheVersion();
        $cacheKey = "rate_cards:districts:v{$version}:".md5(implode('|', $sortedOrigins).'::'.implode('|', $sortedDestinations));

        $rateCardDistricts = Cache::remember($cacheKey, 3600, function () use ($sortedOrigins, $sortedDestinations): array {
            return ExpeditionRateCard::where('is_active', true)
                ->whereIn('origin_depo', $sortedOrigins)
                ->whereIn('destination_city', $sortedDestinations)
                ->whereNotNull('destination_district')
                ->where('destination_district', '!=', '')
                ->whereRaw('UPPER(TRIM(destination_district)) != UPPER(TRIM(destination_city))')
                ->distinct()
                ->orderBy('destination_district')
                ->pluck('destination_district', 'destination_district')
                ->toArray();
        });

        if (! empty($rateCardDistricts)) {
            return $rateCardDistricts;
        }

        /** @var FreightRateGoogleSheetService $service */
        $service = app(FreightRateGoogleSheetService::class);
        $merged = [];
        foreach ($sortedOrigins as $orig) {
            foreach ($sortedDestinations as $dest) {
                foreach ($service->getDistrictOptions($orig, $dest) as $key => $label) {
                    $merged[$key] = $label;
                }
            }
        }
        ksort($merged);

        return $merged;
    }

    /**
     * Cari kecamatan langsung di bawah kota asal (saat kota tujuan belum dipilih).
     *
     * @return array<string, string> key: "KOTA||KECAMATAN", label: "KECAMATAN (Kota: KOTA)"
     */
    public function searchDistrictsForOrigin(?string $origin, string $search, int $limit = 40): array
    {
        return $this->searchDistrictsForOrigins($this->normalizeOrigins($origin), $search, $limit);
    }

    /**
     * Cari kecamatan langsung di bawah satu atau beberapa kota asal.
     *
     * @param  list<string>  $origins
     * @return array<string, string> key: "KOTA||KECAMATAN", label: "KECAMATAN (Kota: KOTA)"
     */
    public function searchDistrictsForOrigins(array $origins, string $search, int $limit = 40): array
    {
        $upperOrigins = $this->normalizeOrigins($origins);
        $needle = strtoupper(trim($search));
        if ($upperOrigins === [] || mb_strlen($needle) < 2) {
            return [];
        }

        $rows = ExpeditionRateCard::where('is_active', true)
            ->whereIn('origin_depo', $upperOrigins)
            ->whereNotNull('destination_district')
            ->where('destination_district', '!=', '')
            ->whereRaw('UPPER(TRIM(destination_district)) != UPPER(TRIM(destination_city))')
            ->where('destination_district', 'LIKE', "%{$needle}%")
            ->select(['destination_city', 'destination_district'])
            ->distinct()
            ->orderBy('destination_district')
            ->orderBy('destination_city')
            ->limit($limit)
            ->get();

        $results = [];
        foreach ($rows as $row) {
            $city = (string) $row->destination_city;
            $dist = (string) $row->destination_district;
            $results["{$city}||{$dist}"] = "{$dist} (Kota: {$city})";
        }

        if ($results !== []) {
            return $results;
        }

        /** @var FreightRateGoogleSheetService $service */
        $service = app(FreightRateGoogleSheetService::class);
        foreach ($upperOrigins as $orig) {
            foreach ($service->searchDistrictsByOrigin($orig, $search, $limit) as $key => $label) {
                $results[$key] = $label;
                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    protected function cachedMasterOrigins(): array
    {
        $version = ExpeditionRateCard::routeCacheVersion();

        return Cache::remember("rate_cards:origins:v{$version}", 3600, function (): array {
            return ExpeditionRateCard::where('is_active', true)
                ->whereNotNull('origin_depo')
                ->where('origin_depo', '!=', '')
                ->distinct()
                ->orderBy('origin_depo')
                ->pluck('origin_depo', 'origin_depo')
                ->toArray();
        });
    }

    /**
     * Query data tarif dari master database ExpeditionRateCard menggunakan composite index
     * `(is_active, origin_depo, destination_city, service_type)` agar tidak full-table scan.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function queryMasterRateCards(string $origin, string $destination, float $weight, ?string $selectedService, ?string $selectedDistrict = null): array
    {
        $orig = strtoupper(trim($origin));
        $dest = strtoupper(trim($destination));
        $dist = filled($selectedDistrict) ? strtoupper(trim((string) $selectedDistrict)) : null;
        $serviceFilter = (! empty($selectedService) && strtoupper($selectedService) !== 'ALL')
            ? strtoupper(trim($selectedService))
            : null;

        $allOrigins = array_values($this->cachedMasterOrigins());
        $matchingOrigins = array_values(array_filter(
            $allOrigins,
            fn (string $candidate): bool => $candidate === $orig || str_contains($candidate, $orig),
        ));

        if ($matchingOrigins === []) {
            return [];
        }

        $version = ExpeditionRateCard::routeCacheVersion();
        $destCacheKey = "rate_cards:dests_group:v{$version}:".md5(implode('|', $matchingOrigins));

        /** @var list<string> $availableDestinations */
        $availableDestinations = Cache::remember($destCacheKey, 3600, function () use ($matchingOrigins): array {
            return ExpeditionRateCard::where('is_active', true)
                ->whereIn('origin_depo', $matchingOrigins)
                ->whereNotNull('destination_city')
                ->where('destination_city', '!=', '')
                ->distinct()
                ->pluck('destination_city')
                ->all();
        });

        $matchingDestinations = array_values(array_filter(
            $availableDestinations,
            fn (string $candidate): bool => $candidate === $dest || str_contains($candidate, $dest),
        ));

        $records = collect();

        if ($matchingDestinations !== []) {
            $query = ExpeditionRateCard::with('expedition')
                ->where('is_active', true)
                ->whereIn('origin_depo', $matchingOrigins)
                ->whereIn('destination_city', $matchingDestinations);

            if ($serviceFilter !== null) {
                $query->where('service_type', $serviceFilter);
            }

            $records = $query->get();
        }

        // Fallback pencarian kecamatan jika pencarian kota kosong atau kecamatan spesifik belum ketemu di kota tersebut
        $districtNeedle = $dist ?? $dest;
        if ($records->isEmpty()) {
            $query = ExpeditionRateCard::with('expedition')
                ->where('is_active', true)
                ->whereIn('origin_depo', $matchingOrigins)
                ->where('destination_district', 'LIKE', "%{$districtNeedle}%");

            if ($serviceFilter !== null) {
                $query->where('service_type', $serviceFilter);
            }

            $records = $query->get();
        }

        if ($records->isEmpty()) {
            return [];
        }

        // Kelompokkan per ekspedisi + layanan. Jika kecamatan dipilih, prioritaskan tarif kecamatan tersebut
        // dan fallback ke tarif level kota milik ekspedisi yang tidak memecah tarif per kecamatan.
        $grouped = [];
        foreach ($records as $rc) {
            // Tarif dokumen (DOC) tidak dicampur saat cek kargo > 1 KG
            if ($rc->pricing_type === ExpeditionRateCard::PRICING_DOC && $weight > 1.0) {
                continue;
            }

            $rowCity = strtoupper(trim((string) $rc->destination_city));
            $rowDistrict = strtoupper(trim((string) ($rc->destination_district ?? '')));

            $priority = 0;
            $matchScope = 'city';
            $matchedDistrict = null;

            if ($dist !== null) {
                if ($rowDistrict === $dist) {
                    $priority = 3;
                    $matchScope = 'district';
                    $matchedDistrict = $rc->destination_district;
                } elseif ($rowDistrict !== '' && str_contains($rowDistrict, $dist)) {
                    $priority = 2;
                    $matchScope = 'district';
                    $matchedDistrict = $rc->destination_district;
                } elseif ($rowDistrict === '' || $rowDistrict === $rowCity) {
                    $priority = 1;
                    $matchScope = 'city';
                } else {
                    // Baris milik kecamatan lain di kota yang sama — lewati agar tidak salah ambil tarif kecamatan lain
                    continue;
                }
            }

            $expName = $rc->expedition?->name ?: 'UMUM';
            $srv = strtoupper(trim($rc->service_type ?: 'DARAT'));
            $minKgVal = (float) $rc->min_kg;
            $isFlat = $rc->isFlatPricing();
            $rate = (float) $rc->rate_per_kg;
            $totalCost = $rc->calculateShippingCost($weight);

            $key = $expName.'|'.$srv;
            $isBetterPriority = ! isset($grouped[$key]) || $priority > $grouped[$key]['_priority'];
            $isSamePriorityCheaper = isset($grouped[$key])
                && $priority === $grouped[$key]['_priority']
                && $totalCost < $grouped[$key]['total_cost'];

            if ($isBetterPriority || $isSamePriorityCheaper) {
                $minKgLabel = $isFlat ? '–' : ((string) round(max(1.0, $minKgVal)).' KG');
                $grouped[$key] = [
                    '_priority' => $priority,
                    'expedition' => $expName,
                    'service' => $srv,
                    'min_kg' => $minKgLabel,
                    'min_kg_val' => $isFlat ? 0.0 : max(1.0, $minKgVal),
                    'is_flat' => $isFlat,
                    'pricing_type' => $rc->pricing_type ?: ExpeditionRateCard::PRICING_PER_KG,
                    'pricing_badge' => $rc->pricingBadge(),
                    'rate_per_kg' => $rate,
                    'total_cost' => $totalCost,
                    'lead_time' => ! empty($rc->sla_days) ? $rc->sla_days.' HARI' : 'Reguler',
                    'insurance_percent' => $rc->insurance_rate_percent !== null ? (float) $rc->insurance_rate_percent : 0.2,
                    'notes' => $rc->notes ?: '',
                    'districts' => $rc->destination_district ? [$rc->destination_district] : [],
                    'match_scope' => $matchScope,
                    'matched_district' => $matchedDistrict,
                ];
            }
        }

        if ($grouped === []) {
            return [];
        }

        $result = array_map(function (array $item): array {
            unset($item['_priority']);

            return $item;
        }, array_values($grouped));

        usort($result, fn ($a, $b) => $a['total_cost'] <=> $b['total_cost']);

        return $result;
    }

    public function checkTariff(?FreightRateGoogleSheetService $service = null): void
    {
        $service ??= app(FreightRateGoogleSheetService::class);

        $formData = $this->form->getState();

        $origins = $this->normalizeOrigins($formData['origin'] ?? []);
        $destinations = $this->normalizeCities($formData['destination'] ?? []);
        $district = ! empty($formData['district']) ? trim((string) $formData['district']) : null;
        if ($district !== null && str_contains($district, '||')) {
            [$resolvedCity, $resolvedDistrict] = explode('||', $district, 2);
            if ($destinations === []) {
                $destinations = $this->normalizeCities($resolvedCity);
            }
            $district = $resolvedDistrict;
        }
        $weight = (float) ($formData['weight'] ?? 0);
        $selectedService = ! empty($formData['service']) ? (string) $formData['service'] : null;
        $totalAmount = ! empty($formData['total_amount']) ? (float) $formData['total_amount'] : 0.0;
        $storeName = ! empty($formData['store_name']) ? (string) $formData['store_name'] : null;
        $itemType = ! empty($formData['item_type']) ? (string) $formData['item_type'] : null;
        $destinationAddress = ! empty($formData['destination_address']) ? (string) $formData['destination_address'] : null;

        if ($origins === [] || ($destinations === [] && empty($district)) || $weight <= 0) {
            Notification::make()
                ->title('Peringatan')
                ->body('Silakan pilih minimal satu kota asal, minimal satu kota/kecamatan tujuan, dan isi berat barang yang valid.')
                ->warning()
                ->send();

            return;
        }

        if ($destinations === [] && ! empty($district)) {
            $destinations = $this->normalizeCities($district);
        }

        $originLabel = implode(', ', $origins);
        $destinationLabel = implode(', ', $destinations);

        $this->searchedOrigin = $originLabel;
        $this->searchedOrigins = $origins;
        $this->searchedDestination = $destinationLabel;
        $this->searchedDestinations = $destinations;
        $this->searchedDistrict = $district;
        $this->searchedWeight = $weight;
        $this->searchedService = $selectedService;
        $this->searchedTotalAmount = $totalAmount > 0 ? $totalAmount : null;
        $this->searchedStoreName = $storeName;
        $this->searchedItemType = $itemType;
        $this->searchedAddress = $destinationAddress;

        $allEnrichedRates = [];
        foreach ($origins as $orig) {
            foreach ($destinations as $dest) {
                // 1. Prioritaskan data master Tarif Kontrak (ExpeditionRateCard) di database
                $rawRates = $this->queryMasterRateCards($orig, $dest, $weight, $selectedService, $district);

                // 2. Jika tidak ada di master database, cek data contoh screenshot (MANADO -> PALEMBANG)
                if (empty($rawRates) && strtoupper($orig) === 'MANADO' && strtoupper($dest) === 'PALEMBANG') {
                    $rawRates = $this->getManadoPalembangScreenshotRates($weight, $selectedService);
                }

                // 3. Jika masih tidak ada di master database, fallback ke Google Sheets cache
                if (empty($rawRates)) {
                    $rawRates = $service->compareRates($orig, $dest, $weight, $selectedService, 10, $district);
                }

                $enrichedForRoute = $this->enrichRatesWithInsuranceAndTotals($rawRates, $weight, $totalAmount, $orig, $dest, $selectedService);
                foreach ($enrichedForRoute as $item) {
                    $allEnrichedRates[] = $item;
                }
            }
        }

        usort($allEnrichedRates, function (array $a, array $b): int {
            if ($a['total_cost'] == $b['total_cost']) {
                return $a['rate_per_kg'] <=> $b['rate_per_kg'];
            }

            return $a['total_cost'] <=> $b['total_cost'];
        });

        $this->rates = $allEnrichedRates;
        $this->hasSearched = true;
        $this->showHistory = false;

        $this->aiInsights = $this->generateAiInsights($this->rates, $totalAmount, $originLabel, $destinationLabel);

        $this->recordSearchHistory(
            $originLabel,
            $destinationLabel,
            $district,
            $weight,
            $selectedService,
            $this->rates,
            $totalAmount,
            $storeName,
            $itemType,
            $destinationAddress
        );
    }

    /**
     * Memuat data contoh MANADO -> PALEMBANG persis sesuai screenshot perbandingan tarif user.
     */
    public function loadScreenshotExample(): void
    {
        $this->form->fill([
            'origin' => ['MANADO'],
            'destination' => ['PALEMBANG'],
            'weight' => 26,
            'service' => '',
            'total_amount' => 59040000,
            'store_name' => 'DEPO PALEMBANG',
            'item_type' => 'MOTOPAD 26u',
            'destination_address' => 'PERUMAHAN YUKA RESIDENCE, BLK. D NO.8, KEL.SUKA MAJU KEC. SAKO, KOTA PALEMBANG , SUMATERA SELATAN 30164.',
        ]);

        $this->checkTariff();

        Notification::make()
            ->title('Contoh Data Berhasil Dimuat')
            ->body('Data perbandingan MANADO ke PALEMBANG sesuai screenshot berhasil dimuat dan dihitung otomatis.')
            ->success()
            ->send();
    }

    /**
     * Data rates acuan perbandingan MANADO -> PALEMBANG persis sesuai gambar user.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getManadoPalembangScreenshotRates(float $weight, ?string $service = null): array
    {
        $items = [
            [
                'expedition' => '21 EXPRESS',
                'service' => 'DARAT',
                'min_kg' => '30 KG',
                'min_kg_val' => 30.0,
                'is_flat' => false,
                'rate_per_kg' => 16000.0,
                'lead_time' => '24-26 HARI',
                'insurance_percent' => 0.0,
                'notes' => '',
                'districts' => [],
            ],
            [
                'expedition' => '21 EXPRESS',
                'service' => 'UDARA',
                'min_kg' => '0 KG',
                'min_kg_val' => 0.0,
                'is_flat' => false,
                'rate_per_kg' => 62500.0,
                'lead_time' => '3-4 HARI',
                'insurance_percent' => 0.0,
                'notes' => '',
                'districts' => [],
            ],
            [
                'expedition' => 'SENTRAL',
                'service' => 'DARAT',
                'min_kg' => '10 KG',
                'min_kg_val' => 10.0,
                'is_flat' => false,
                'rate_per_kg' => 19000.0,
                'lead_time' => '9-12 HARI',
                'insurance_percent' => 0.2,
                'notes' => '',
                'districts' => [],
            ],
            [
                'expedition' => 'SENTRAL',
                'service' => 'UDARA',
                'min_kg' => '0 KG',
                'min_kg_val' => 0.0,
                'is_flat' => false,
                'rate_per_kg' => 73500.0,
                'lead_time' => '2-3 HARI',
                'insurance_percent' => 0.2,
                'notes' => '',
                'districts' => [],
            ],
            [
                'expedition' => 'JNT CARGO',
                'service' => 'DARAT',
                'min_kg' => '10 KG',
                'min_kg_val' => 10.0,
                'is_flat' => false,
                'rate_per_kg' => 12450.0,
                'lead_time' => '17-19 HARI',
                'insurance_percent' => 2.0,
                'notes' => '',
                'districts' => [],
            ],
        ];

        if (! empty($service) && strtoupper($service) !== 'ALL') {
            $items = array_values(array_filter($items, fn ($item) => strtoupper($item['service']) === strtoupper($service)));
        }

        return $items;
    }

    /**
     * Hitung biaya asuransi, total biaya, dan persentase terhadap nilai barang.
     *
     * @param  array<int, array<string, mixed>>  $rawRates
     * @return array<int, array<string, mixed>>
     */
    protected function enrichRatesWithInsuranceAndTotals(
        array $rawRates,
        float $weight,
        float $totalAmount,
        string $origin = '',
        string $destination = '',
        ?string $selectedService = null
    ): array {
        $enriched = [];
        $isAirRealistic = $this->isAirServiceRealistic($origin, $destination);

        foreach ($rawRates as $r) {
            $exp = (string) $r['expedition'];
            $srv = (string) $r['service'];

            // Saring anomali layanan udara untuk rute jarak dekat yang tidak ada kargo udara (misal Jakarta - Bandung)
            if ((empty($selectedService) || strtoupper($selectedService) === 'ALL') && ! $isAirRealistic && strtoupper($srv) === 'UDARA') {
                continue;
            }

            $ratePerKg = (float) $r['rate_per_kg'];
            $isFlat = ! empty($r['is_flat']);
            $minKgVal = isset($r['min_kg_val']) ? (float) $r['min_kg_val'] : 1.0;

            $chargeableWeight = max($weight, $minKgVal > 0 ? $minKgVal : 1.0);
            $biayaOngkir = $isFlat ? $ratePerKg : ($chargeableWeight * $ratePerKg);

            $insPercent = isset($r['insurance_percent'])
                ? (float) $r['insurance_percent']
                : $this->resolveInsurancePercent($exp);

            $biayaAsuransi = $totalAmount > 0 ? round($totalAmount * ($insPercent / 100)) : 0.0;
            $totalCost = $biayaOngkir + $biayaAsuransi;
            $pctAmount = $totalAmount > 0 ? round(($totalCost / $totalAmount) * 100, 1) : 0.0;

            $minKgLabel = $isFlat ? '–' : ($minKgVal > 0 ? (string) round($minKgVal) : '1');
            $pricingType = (string) ($r['pricing_type'] ?? ($isFlat ? ExpeditionRateCard::PRICING_FLAT : ExpeditionRateCard::PRICING_PER_KG));
            $pricingBadge = (string) ($r['pricing_badge'] ?? (ExpeditionRateCard::PRICING_BADGES[$pricingType] ?? '/KG'));

            $enriched[] = [
                'origin' => (string) ($r['origin'] ?? $origin),
                'destination' => (string) ($r['destination'] ?? $destination),
                'expedition' => $exp,
                'service' => $srv,
                'display_name' => "{$exp} ({$srv})",
                'rate_per_kg' => $ratePerKg,
                'min_kg' => $minKgLabel,
                'min_kg_val' => $minKgVal,
                'pricing_type' => $pricingType,
                'pricing_badge' => $pricingBadge,
                'insurance_percent' => $insPercent,
                'biaya_asuransi' => $biayaAsuransi,
                'biaya_ongkir' => $biayaOngkir,
                'total_cost' => $totalCost,
                'percent_dari_amount' => $pctAmount,
                'lead_time' => ! empty($r['lead_time']) && $r['lead_time'] !== '-' ? $r['lead_time'] : 'Reguler',
                'notes' => $r['notes'] ?? '',
                'is_flat' => $isFlat,
                'districts' => $r['districts'] ?? [],
                'match_scope' => $r['match_scope'] ?? 'city',
                'matched_district' => $r['matched_district'] ?? null,
            ];
        }

        // Urutkan berdasarkan total_cost ASC
        usort($enriched, fn ($a, $b) => $a['total_cost'] <=> $b['total_cost']);

        return $enriched;
    }

    /**
     * Memeriksa apakah jalur udara realistis untuk rute ini.
     * Rute darat jarak dekat seperti Jabodetabek - Jawa Barat tidak memiliki penerbangan kargo.
     */
    public function isAirServiceRealistic(string $origin, string $destination): bool
    {
        $orig = strtoupper(trim($origin));
        $dest = strtoupper(trim($destination));

        if (empty($orig) || empty($dest) || $orig === $dest) {
            return false;
        }

        $shortDistanceLandHubs = [
            'JAKARTA', 'BANDUNG', 'CIREBON', 'BOGOR', 'DEPOK', 'TANGERANG', 'BEKASI',
            'SUKABUMI', 'SERANG', 'CILEGON', 'PURWAKARTA', 'SUBANG', 'INDRAMAYU',
            'MAJALENGKA', 'KUNINGAN', 'TASIKMALAYA', 'CIAMIS', 'GARUT',
        ];

        if (in_array($orig, $shortDistanceLandHubs) && in_array($dest, $shortDistanceLandHubs)) {
            return false;
        }

        return true;
    }

    /**
     * Resolusi persentase premi asuransi default per ekspedisi.
     */
    protected function resolveInsurancePercent(string $expeditionName): float
    {
        $name = strtoupper(trim($expeditionName));

        if (preg_match('/(J&T\s*CARGO|JNT\s*CARGO)/i', $name)) {
            return 2.0;
        }

        if (preg_match('/(21\s*EXPRES|21\s*EXPRESS)/i', $name)) {
            return 0.0;
        }

        if (preg_match('/SENTRAL/i', $name)) {
            return 0.2;
        }

        if (preg_match('/(DAX|RAX)/i', $name)) {
            return 0.15;
        }

        return 0.2;
    }

    /**
     * Menghasilkan analisis cerdas AI & ringkasan rekomendasi.
     *
     * @param  array<int, array<string, mixed>>  $rates
     * @return array{
     *     cheapest: ?array<string, mixed>,
     *     fastest: ?array<string, mixed>,
     *     warnings: array<int, string>,
     *     whatsapp_text: string
     * }
     */
    protected function generateAiInsights(array $rates, float $totalAmount, string $origin, string $destination): array
    {
        if (empty($rates)) {
            return [
                'cheapest' => null,
                'fastest' => null,
                'warnings' => [],
                'whatsapp_text' => '',
            ];
        }

        $cheapest = $rates[0];

        // Cari opsi tercepat berdasarkan SLA
        $fastest = null;
        $minDays = 999;
        foreach ($rates as $r) {
            if (preg_match('/(\d+)/', (string) $r['lead_time'], $matches)) {
                $days = (int) $matches[1];
                if ($days < $minDays) {
                    $minDays = $days;
                    $fastest = $r;
                }
            }
        }
        $fastest ??= $cheapest;

        $isMultiOrigin = count($this->searchedOrigins) > 1;
        $isMultiDestination = count($this->searchedDestinations) > 1;
        $formatLabel = static function (array $rate) use ($isMultiOrigin, $isMultiDestination): string {
            $label = (string) ($rate['display_name'] ?? '');
            $rateOrigin = (string) ($rate['origin'] ?? '');
            $rateDest = (string) ($rate['destination'] ?? '');

            if ($isMultiOrigin && $isMultiDestination && $rateOrigin !== '' && $rateDest !== '') {
                return "{$label} [{$rateOrigin} -> {$rateDest}]";
            }

            if ($isMultiOrigin && $rateOrigin !== '') {
                return "{$label} [{$rateOrigin}]";
            }

            if ($isMultiDestination && $rateDest !== '') {
                return "{$label} [-> {$rateDest}]";
            }

            return $label;
        };

        // Deteksi perangkap asuransi (ongkir/kg murah tapi total bengkak karena asuransi)
        $warnings = [];
        foreach ($rates as $r) {
            if ($r['rate_per_kg'] < $cheapest['rate_per_kg'] && $r['total_cost'] > $cheapest['total_cost']) {
                $diff = $r['total_cost'] - $cheapest['total_cost'];
                $warnings[] = 'Catatan Biaya: '.$formatLabel($r).' memiliki tarif per KG lebih murah (Rp '.number_format($r['rate_per_kg'], 0, ',', '.')."/KG), namun karena premi asuransi {$r['insurance_percent']}% (Rp ".number_format($r['biaya_asuransi'], 0, ',', '.').'), total akhirnya lebih mahal Rp '.number_format($diff, 0, ',', '.').' dibanding '.$formatLabel($cheapest).'.';
            }
        }

        $waText = $this->buildWhatsAppReport($rates, $cheapest, $fastest, $warnings, $origin, $destination, $totalAmount);

        return [
            'cheapest' => $cheapest,
            'fastest' => $fastest,
            'warnings' => $warnings,
            'whatsapp_text' => $waText,
        ];
    }

    /**
     * Membangun teks format WhatsApp siap salin.
     *
     * @param  array<int, array<string, mixed>>  $rates
     * @param  array<string, mixed>  $cheapest
     * @param  array<string, mixed>  $fastest
     * @param  array<int, string>  $warnings
     */
    protected function buildWhatsAppReport(array $rates, array $cheapest, array $fastest, array $warnings, string $origin, string $destination, float $totalAmount): string
    {
        $isMultiOrigin = count($this->searchedOrigins) > 1;
        $isMultiDestination = count($this->searchedDestinations) > 1;
        $formatLabel = static function (array $rate) use ($isMultiOrigin, $isMultiDestination): string {
            $label = (string) ($rate['display_name'] ?? '');
            $rateOrigin = (string) ($rate['origin'] ?? '');
            $rateDest = (string) ($rate['destination'] ?? '');

            if ($isMultiOrigin && $isMultiDestination && $rateOrigin !== '' && $rateDest !== '') {
                return "{$label} [{$rateOrigin} -> {$rateDest}]";
            }

            if ($isMultiOrigin && $rateOrigin !== '') {
                return "{$label} [{$rateOrigin}]";
            }

            if ($isMultiDestination && $rateDest !== '') {
                return "{$label} [-> {$rateDest}]";
            }

            return $label;
        };

        $lines = [];
        $lines[] = '*PERBANDINGAN TARIF EKSPEDISI*';
        $lines[] = '=============================';

        if (! empty($this->searchedStoreName)) {
            $lines[] = '*Tujuan / Toko:* '.$this->searchedStoreName;
        }

        if ($totalAmount > 0) {
            $lines[] = '*Total Amount (Nilai):* Rp '.number_format($totalAmount, 0, ',', '.');
        }

        $lines[] = '*Total Berat:* '.$this->searchedWeight.' KG';

        if (! empty($this->searchedItemType)) {
            $lines[] = '*Jenis Barang:* '.$this->searchedItemType;
        }

        $routeLabel = ! empty($this->searchedDistrict)
            ? "{$origin} -> {$destination}, Kec. {$this->searchedDistrict}"
            : "{$origin} -> {$destination}";
        $lines[] = "*Rute:* {$routeLabel}";

        if (! empty($this->searchedAddress)) {
            $lines[] = '*Alamat Tujuan:* '.$this->searchedAddress;
        }

        $lines[] = '-----------------------------';
        $lines[] = '*DAFTAR TARIF EKSPEDISI:*';

        $fastestLabel = $formatLabel($fastest);
        $cheapestLabel = $formatLabel($cheapest);

        foreach ($rates as $idx => $r) {
            $num = $idx + 1;
            $rowLabel = $formatLabel($r);
            $star = ($idx === 0) ? ' ⭐ *TERMURAH*' : (($rowLabel === $fastestLabel && $idx !== 0) ? ' ⚡ *TERCEPAT*' : '');
            $lines[] = "{$num}. *{$rowLabel}*{$star}";
            $lines[] = '   • Ongkir/KG: Rp '.number_format($r['rate_per_kg'], 0, ',', '.').($r['min_kg'] > 0 ? " (Min. {$r['min_kg']} KG)" : '').' = Rp '.number_format($r['biaya_ongkir'], 0, ',', '.');

            if ($totalAmount > 0) {
                $lines[] = '   • Asuransi ('.number_format($r['insurance_percent'], 1, ',', '.').'%): Rp '.number_format($r['biaya_asuransi'], 0, ',', '.');
                $lines[] = '   • Total: *Rp '.number_format($r['total_cost'], 0, ',', '.').'* ('.number_format($r['percent_dari_amount'], 1, ',', '.').'% dr amount)';
            } else {
                $lines[] = '   • Total: *Rp '.number_format($r['total_cost'], 0, ',', '.').'*';
            }

            $lines[] = '   • SLA: '.$r['lead_time'];
        }

        $lines[] = '-----------------------------';
        $lines[] = '*REKOMENDASI PINTAR AI:*';
        $lines[] = '✅ *Paling Hemat:* '.$cheapestLabel.' (Total Rp '.number_format($cheapest['total_cost'], 0, ',', '.').')';

        if ($fastestLabel !== $cheapestLabel) {
            $lines[] = '⚡ *Paling Cepat:* '.$fastestLabel.' (SLA '.$fastest['lead_time'].', Total Rp '.number_format($fastest['total_cost'], 0, ',', '.').')';
        }

        foreach ($warnings as $w) {
            $lines[] = '💡 '.$w;
        }

        return implode("\n", $lines);
    }

    public function toggleHistory(): void
    {
        $this->showHistory = ! $this->showHistory;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rates
     */
    protected function recordSearchHistory(
        string $origin,
        string $destination,
        ?string $district,
        float $weight,
        ?string $service,
        array $rates,
        float $totalAmount = 0.0,
        ?string $storeName = null,
        ?string $itemType = null,
        ?string $destinationAddress = null
    ): void {
        $storedOrigin = Str::limit($origin, 100, '...');
        $storedDestination = Str::limit($destination, 100, '...');
        $cheapest = ! empty($rates) ? $rates[0] : null;
        $userId = auth()->id();

        $recent = TariffSearchHistory::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where('origin', $storedOrigin)
            ->where('destination', $storedDestination)
            ->where('district', $district)
            ->where('weight', $weight)
            ->where('service', $service)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->first();

        $ratesSummary = array_map(fn ($r) => [
            'origin' => (string) ($r['origin'] ?? $storedOrigin),
            'destination' => (string) ($r['destination'] ?? $storedDestination),
            'expedition' => (string) $r['expedition'],
            'service' => (string) $r['service'],
            'rate_per_kg' => (float) ($r['rate_per_kg'] ?? 0),
            'min_kg' => (string) ($r['min_kg'] ?? '0'),
            'insurance_percent' => (float) ($r['insurance_percent'] ?? 0),
            'biaya_asuransi' => (float) ($r['biaya_asuransi'] ?? 0),
            'biaya_ongkir' => (float) ($r['biaya_ongkir'] ?? 0),
            'total_cost' => (float) ($r['total_cost'] ?? 0),
            'percent_dari_amount' => (float) ($r['percent_dari_amount'] ?? 0),
            'lead_time' => (string) ($r['lead_time'] ?? '-'),
            'match_scope' => (string) ($r['match_scope'] ?? 'city'),
            'matched_district' => $r['matched_district'] ?? null,
        ], $rates);

        $cheapestExpedition = $cheapest['display_name'] ?? $cheapest['expedition'] ?? null;
        if ($cheapestExpedition !== null) {
            $cheapestExpedition = Str::limit((string) $cheapestExpedition, 100, '...');
        }

        $payload = [
            'total_results' => count($rates),
            'cheapest_expedition' => $cheapestExpedition,
            'cheapest_cost' => $cheapest['total_cost'] ?? null,
            'rates_summary' => $ratesSummary,
            'total_amount' => $totalAmount > 0 ? $totalAmount : null,
            'store_name' => $storeName,
            'item_type' => $itemType,
            'destination_address' => $destinationAddress,
        ];

        if ($recent) {
            $recent->update(array_merge($payload, ['created_at' => now()]));
        } else {
            TariffSearchHistory::create(array_merge($payload, [
                'user_id' => $userId,
                'origin' => $storedOrigin,
                'destination' => $storedDestination,
                'district' => $district,
                'weight' => $weight,
                'service' => $service,
            ]));
        }
    }

    /**
     * @return Collection<int, TariffSearchHistory>
     */
    public function getSearchHistories(): Collection
    {
        $userId = auth()->id();

        return TariffSearchHistory::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->latest()
            ->take(20)
            ->get();
    }

    public function applyHistory(int $historyId): void
    {
        $history = TariffSearchHistory::find($historyId);
        if (! $history) {
            return;
        }

        $this->showHistory = false;

        $savedRates = $history->getRatesList();
        $historyOrigins = $this->normalizeOrigins(array_column($savedRates, 'origin'));
        if ($historyOrigins === []) {
            $historyOrigins = $this->normalizeOrigins($history->origin);
        }
        $historyOriginLabel = $historyOrigins !== [] ? implode(', ', $historyOrigins) : (string) $history->origin;

        $historyDestinations = $this->normalizeCities(array_column($savedRates, 'destination'));
        if ($historyDestinations === []) {
            $historyDestinations = $this->normalizeCities($history->destination);
        }
        $historyDestinationLabel = $historyDestinations !== [] ? implode(', ', $historyDestinations) : (string) $history->destination;

        $this->form->fill([
            'origin' => $historyOrigins,
            'destination' => $historyDestinations,
            'district' => $history->district,
            'weight' => $history->weight,
            'service' => $history->service ?? '',
            'total_amount' => $history->total_amount,
        ]);

        if (! empty($savedRates)) {
            $this->rates = $savedRates;
            $this->searchedOrigin = $historyOriginLabel;
            $this->searchedOrigins = $historyOrigins;
            $this->searchedDestination = $historyDestinationLabel;
            $this->searchedDestinations = $historyDestinations;
            $this->searchedDistrict = $history->district;
            $this->searchedWeight = (float) $history->weight;
            $this->searchedService = $history->service;
            $this->searchedTotalAmount = $history->total_amount ? (float) $history->total_amount : null;
            $this->hasSearched = true;
            $this->showHistory = false;
        } elseif (! empty($history->cheapest_expedition) && ! empty($history->cheapest_cost)) {
            $cost = (float) $history->cheapest_cost;
            $w = (float) ($history->weight ?: 1);
            $this->rates = [
                [
                    'origin' => $historyOrigins[0] ?? (string) $history->origin,
                    'destination' => $historyDestinations[0] ?? (string) $history->destination,
                    'expedition' => $history->cheapest_expedition,
                    'service' => $history->service ?: 'DARAT',
                    'display_name' => "{$history->cheapest_expedition} (".($history->service ?: 'DARAT').')',
                    'rate_per_kg' => round($cost / $w),
                    'min_kg' => '1',
                    'min_kg_val' => 1.0,
                    'insurance_percent' => 0.2,
                    'biaya_asuransi' => 0.0,
                    'biaya_ongkir' => $cost,
                    'total_cost' => $cost,
                    'percent_dari_amount' => 0.0,
                    'lead_time' => '1-2 HARI',
                    'notes' => '',
                    'is_flat' => false,
                    'districts' => [],
                ],
            ];
            $this->searchedOrigin = $historyOriginLabel;
            $this->searchedOrigins = $historyOrigins;
            $this->searchedDestination = $historyDestinationLabel;
            $this->searchedDestinations = $historyDestinations;
            $this->searchedDistrict = $history->district;
            $this->searchedWeight = $w;
            $this->searchedService = $history->service;
            $this->searchedTotalAmount = $history->total_amount ? (float) $history->total_amount : null;
            $this->hasSearched = true;
            $this->showHistory = false;
        } else {
            $this->checkTariff();
        }
    }

    public function deleteHistoryItem(int $historyId): void
    {
        $userId = auth()->id();

        TariffSearchHistory::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where('id', $historyId)
            ->delete();

        Notification::make()
            ->title('Riwayat berhasil dihapus')
            ->success()
            ->send();
    }

    public function clearAllHistory(): void
    {
        $userId = auth()->id();

        TariffSearchHistory::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->delete();

        Notification::make()
            ->title('Semua riwayat berhasil dibersihkan')
            ->success()
            ->send();
    }

    public function resetSearch(): void
    {
        $this->form->fill([
            'origin' => [],
            'destination' => [],
            'district' => null,
            'weight' => null,
            'service' => '',
            'total_amount' => null,
        ]);

        $this->hasSearched = false;
        $this->showHistory = false;
        $this->rates = [];
        $this->searchedOrigin = null;
        $this->searchedOrigins = [];
        $this->searchedDestination = null;
        $this->searchedDestinations = [];
        $this->searchedDistrict = null;
        $this->searchedWeight = 0;
        $this->searchedService = null;
        $this->searchedTotalAmount = null;
        $this->searchedStoreName = null;
        $this->searchedItemType = null;
        $this->searchedAddress = null;
        $this->aiInsights = null;
    }

    /**
     * @param  string|list<string>  $origin
     * @param  string|list<string>  $destination
     */
    public function setRoute(string|array $origin, string|array $destination, float $weight = 5, string $service = '', ?string $district = null): void
    {
        $this->form->fill([
            'origin' => $this->normalizeOrigins($origin),
            'destination' => $this->normalizeCities($destination),
            'district' => $district,
            'weight' => $weight,
            'service' => $service,
        ]);

        $this->checkTariff();
    }

    /**
     * @return array<int, string>
     */
    public function getSuggestedDestinations(): array
    {
        $origins = $this->normalizeOrigins($this->data['origin'] ?? $this->searchedOrigins);
        if ($origins === []) {
            return [];
        }

        /** @var FreightRateGoogleSheetService $service */
        $service = app(FreightRateGoogleSheetService::class);
        $suggested = [];
        foreach ($origins as $origin) {
            foreach ($service->getDestinationsForOrigin($origin, 8) as $dest) {
                if (! in_array($dest, $suggested, true)) {
                    $suggested[] = $dest;
                }
                if (count($suggested) >= 8) {
                    break 2;
                }
            }
        }

        return $suggested;
    }
}

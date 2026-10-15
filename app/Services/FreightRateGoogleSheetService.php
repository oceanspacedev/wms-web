<?php

namespace App\Services;

use App\Models\ExpeditionRateCard;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PDO;

class FreightRateGoogleSheetService
{
    protected string $spreadsheetCsvUrl;

    protected string $sqlitePath;

    public function __construct(?string $spreadsheetCsvUrl = null, ?string $sqlitePath = null)
    {
        $this->spreadsheetCsvUrl = $spreadsheetCsvUrl ?? (string) config(
            'services.google_sheets.rates_csv_url',
            'https://docs.google.com/spreadsheets/d/1fd-3VqrxFxU_1icUOh2AaKlNulOQKF-32A3WJ2UeLn4/export?format=csv&gid=1319753844'
        );

        $this->sqlitePath = $sqlitePath ?? (string) config(
            'services.google_sheets.rates_cache_db',
            storage_path('app/freight_rates.sqlite')
        );
    }

    /**
     * Get PDO connection to SQLite cache.
     */
    protected function getDb(): PDO
    {
        $dir = dirname($this->sqlitePath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $isNew = ! file_exists($this->sqlitePath) || filesize($this->sqlitePath) === 0;

        $db = new PDO('sqlite:'.$this->sqlitePath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('PRAGMA journal_mode = WAL');

        if ($isNew) {
            $this->createSchema($db);
        }

        return $db;
    }

    /**
     * Create SQLite schema with optimal indexes.
     */
    protected function createSchema(PDO $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS meta (
                key TEXT PRIMARY KEY,
                value TEXT
            );

            CREATE TABLE IF NOT EXISTS rates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                expedition TEXT NOT NULL,
                origin TEXT NOT NULL,
                province TEXT,
                destination_city TEXT NOT NULL,
                destination_district TEXT,
                min_kg TEXT,
                min_kg_val REAL NOT NULL DEFAULT 1,
                is_flat INTEGER NOT NULL DEFAULT 0,
                rate_per_kg REAL NOT NULL DEFAULT 0,
                lead_time TEXT,
                service TEXT NOT NULL,
                code TEXT,
                notes TEXT
            );

            CREATE INDEX IF NOT EXISTS idx_rates_orig_dest ON rates (origin, destination_city);
            CREATE INDEX IF NOT EXISTS idx_rates_service ON rates (service);
            CREATE INDEX IF NOT EXISTS idx_rates_district ON rates (destination_district);
        ');
    }

    /**
     * Check if cache database is initialized with data.
     */
    public function isCacheAvailable(): bool
    {
        if (! file_exists($this->sqlitePath) || filesize($this->sqlitePath) === 0) {
            return false;
        }

        try {
            $db = $this->getDb();
            $count = (int) $db->query('SELECT count(*) FROM rates')->fetchColumn();

            return $count > 0;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Get synchronization status and metadata.
     *
     * @return array{is_synced: bool, total_rows: int, synced_at: ?string}
     */
    public function getSyncInfo(): array
    {
        if (! $this->isCacheAvailable()) {
            return [
                'is_synced' => false,
                'total_rows' => 0,
                'synced_at' => null,
            ];
        }

        try {
            $db = $this->getDb();
            $totalRows = (int) $db->query('SELECT count(*) FROM rates')->fetchColumn();
            $stmt = $db->prepare('SELECT value FROM meta WHERE key = ?');
            $stmt->execute(['synced_at']);
            $syncedAt = $stmt->fetchColumn() ?: null;

            return [
                'is_synced' => $totalRows > 0,
                'total_rows' => $totalRows,
                'synced_at' => $syncedAt,
            ];
        } catch (Exception) {
            return [
                'is_synced' => false,
                'total_rows' => 0,
                'synced_at' => null,
            ];
        }
    }

    /**
     * Sync and import rate data directly from Google Spreadsheet CSV.
     *
     * @return array{success: bool, total_imported: int, message: string}
     */
    public function syncFromSpreadsheet(?string $customUrl = null): array
    {
        $url = $customUrl ?: $this->spreadsheetCsvUrl;

        // Download CSV stream/file
        $tempCsv = storage_path('app/temp_rates_sync.csv');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) WMS-TariffSync/1.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);

        $fp = fopen($tempCsv, 'w+');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        $executed = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (! $executed || $httpCode !== 200 || filesize($tempCsv) < 100) {
            if (file_exists($tempCsv)) {
                @unlink($tempCsv);
            }
            throw new Exception("Gagal mengunduh data dari Google Spreadsheet (HTTP {$httpCode}: {$curlError}). Periksa koneksi atau URL spreadsheet.");
        }

        $db = $this->getDb();
        $db->exec('PRAGMA synchronous = OFF');

        // Clean existing records in a transaction
        $db->beginTransaction();
        $db->exec('DELETE FROM rates');

        $stmt = $db->prepare('
            INSERT INTO rates (
                expedition, origin, province, destination_city, destination_district,
                min_kg, min_kg_val, is_flat, rate_per_kg, lead_time, service, code, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $handle = fopen($tempCsv, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '\\');

        $importedCount = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $exp = trim($row[0] ?? '');
            $orig = strtoupper(trim($row[1] ?? ''));
            $prov = trim($row[2] ?? '');
            $dest = strtoupper(trim($row[3] ?? ''));
            $dist = strtoupper(trim($row[4] ?? ''));
            $minKg = trim($row[5] ?? '');
            $priceRaw = trim($row[6] ?? '');
            $lead = trim($row[7] ?? '');
            $serv = strtoupper(trim($row[8] ?? ''));
            $code = trim($row[9] ?? '');
            $notes = trim($row[10] ?? '');

            if ($orig === '' && $dest === '') {
                continue;
            }

            // Extract numeric rate
            $price = (float) preg_replace('/[^\d]/', '', $priceRaw);
            if ($price <= 0) {
                // If price is missing or 0, still insert or keep
            }

            $kgInfo = $this->parseMinKg($minKg);

            $stmt->execute([
                $exp ?: 'UMUM',
                $orig,
                $prov,
                $dest,
                $dist,
                $minKg,
                $kgInfo['val'],
                $kgInfo['is_flat'],
                $price,
                $lead,
                $serv ?: 'DARAT',
                $code,
                $notes,
            ]);

            $importedCount++;
        }

        fclose($handle);
        @unlink($tempCsv);

        // Update meta timestamp
        $metaStmt = $db->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)');
        $metaStmt->execute(['synced_at', now()->toDateTimeString()]);
        $metaStmt->execute(['total_rows', (string) $importedCount]);

        $db->commit();
        $db->exec('PRAGMA synchronous = NORMAL');

        Log::info("Synced {$importedCount} freight rates from Google Spreadsheet.");

        return [
            'success' => true,
            'total_imported' => $importedCount,
            'message' => "Berhasil menyinkronkan {$importedCount} data tarif ekspedisi dari Google Spreadsheet.",
        ];
    }

    /**
     * Synchronize SQLite cache directly from ExpeditionRateCard master data.
     *
     * @return int Total synced records
     */
    public function syncFromRateCards(): int
    {
        $rateCards = ExpeditionRateCard::with('expedition')->where('is_active', true)->get();
        if ($rateCards->isEmpty()) {
            return 0;
        }

        $db = $this->getDb();
        $db->exec('PRAGMA synchronous = OFF');
        $db->beginTransaction();

        $db->exec('DELETE FROM rates');

        $stmt = $db->prepare('
            INSERT INTO rates (
                expedition, origin, province, destination_city, destination_district,
                min_kg, min_kg_val, is_flat, rate_per_kg, lead_time, service, code, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $count = 0;
        foreach ($rateCards as $rc) {
            $exp = $rc->expedition?->name ?: 'UMUM';
            $orig = strtoupper(trim($rc->origin_depo));
            $dest = strtoupper(trim($rc->destination_city));
            $dist = ! empty($rc->destination_district) ? strtoupper(trim($rc->destination_district)) : '';
            $prov = ! empty($rc->province) ? strtoupper(trim($rc->province)) : '';
            $minKgVal = (float) $rc->min_kg;
            $isFlat = $minKgVal == 0 ? 1 : 0;
            $ratePerKg = (float) $rc->rate_per_kg;
            $leadTime = ! empty($rc->sla_days) ? $rc->sla_days.' HARI' : '';
            $service = strtoupper(trim($rc->service_type ?: 'DARAT'));
            $notes = $rc->notes ?: '';

            $stmt->execute([
                $exp,
                $orig,
                $prov,
                $dest,
                $dist,
                $minKgVal > 0 ? (string) round($minKgVal).' KG' : 'FLAT',
                $minKgVal,
                $isFlat,
                $ratePerKg,
                $leadTime,
                $service,
                '',
                $notes,
            ]);

            $count++;
        }

        $metaStmt = $db->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)');
        $metaStmt->execute(['synced_at', now()->toDateTimeString()]);
        $metaStmt->execute(['total_rows', (string) $count]);

        $db->commit();
        $db->exec('PRAGMA synchronous = NORMAL');

        Log::info("Synced {$count} freight rates from ExpeditionRateCard master data to SQLite cache.");

        return $count;
    }

    /**
     * Parse /Kg column into numeric min_kg and is_flat flag.
     *
     * @return array{is_flat: int, val: float}
     */
    protected function parseMinKg(string $minKgStr): array
    {
        $upper = strtoupper(trim($minKgStr));

        if (in_array($upper, ['FLAT', '/COLLY', 'DOC', '/KUBIKASI'])) {
            return ['is_flat' => 1, 'val' => 0];
        }

        if (preg_match('/(\d+)/', $upper, $matches)) {
            return ['is_flat' => 0, 'val' => (float) $matches[1]];
        }

        return ['is_flat' => 0, 'val' => 1];
    }

    /**
     * Get list of unique origin cities.
     *
     * @return array<string, string>
     */
    public function getOrigins(): array
    {
        if (! $this->isCacheAvailable()) {
            return [];
        }

        $db = $this->getDb();
        $stmt = $db->query('SELECT DISTINCT origin FROM rates WHERE origin != "" ORDER BY origin ASC');
        $origins = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $result = [];
        foreach ($origins as $orig) {
            $result[$orig] = $orig;
        }

        return $result;
    }

    /**
     * Get available destination cities specifically for a given origin.
     *
     * @return array<int, string>
     */
    public function getDestinationsForOrigin(string $origin, int $limit = 10): array
    {
        if (! $this->isCacheAvailable()) {
            return [];
        }

        $db = $this->getDb();
        $stmt = $db->prepare('SELECT DISTINCT destination_city FROM rates WHERE origin = ? AND destination_city != "" ORDER BY destination_city ASC LIMIT ?');
        $stmt->bindValue(1, strtoupper(trim($origin)), PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Search destination cities for autocomplete / select.
     *
     * @return array<string, string>
     */
    public function getDestinationOptions(?string $origin = null, ?string $search = null): array
    {
        if (! $this->isCacheAvailable()) {
            return [];
        }

        $db = $this->getDb();
        $sql = 'SELECT DISTINCT destination_city FROM rates WHERE destination_city != ""';
        $params = [];

        if (! empty($origin)) {
            $sql .= ' AND origin = :origin';
            $params[':origin'] = strtoupper(trim($origin));
        }

        if (! empty($search)) {
            $sql .= ' AND destination_city LIKE :search';
            $params[':search'] = '%'.strtoupper(trim($search)).'%';
        }

        $sql .= ' ORDER BY destination_city ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $result = [];
        foreach ($cities as $city) {
            $result[$city] = $city;
        }

        return $result;
    }

    /**
     * Compare freight rates across all expeditions for given route & weight.
     *
     * @return array<int, array{
     *     expedition: string,
     *     service: string,
     *     min_kg: string,
     *     min_kg_val: float,
     *     is_flat: bool,
     *     rate_per_kg: float,
     *     total_cost: float,
     *     formula: string,
     *     lead_time: string,
     *     districts: array<int, string>,
     *     is_recommended: bool
     * }>
     */
    public function compareRates(string $origin, string $destination, float $weight, ?string $service = null, int $limit = 5): array
    {
        if (! $this->isCacheAvailable()) {
            $this->syncFromSpreadsheet();
        }

        $origin = strtoupper(trim($origin));
        $dest = strtoupper(trim($destination));
        $weight = max(0.1, $weight);

        $db = $this->getDb();

        $sql = 'SELECT expedition, origin, province, destination_city, destination_district, min_kg, min_kg_val, is_flat, rate_per_kg, lead_time, service, notes
                FROM rates 
                WHERE (origin = :origin OR origin LIKE :origin_like)
                  AND (destination_city = :dest OR destination_city LIKE :dest_like OR destination_district LIKE :dest_like)';

        $params = [
            ':origin' => $origin,
            ':origin_like' => "%{$origin}%",
            ':dest' => $dest,
            ':dest_like' => "%{$dest}%",
        ];

        if (! empty($service) && strtoupper($service) !== 'ALL') {
            $sql .= ' AND service = :service';
            $params[':service'] = strtoupper(trim($service));
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        // Group by expedition + service to pick the best/lowest rate for each
        $grouped = [];
        foreach ($rows as $r) {
            $exp = $r['expedition'];
            $srv = $r['service'];
            $rate = (float) $r['rate_per_kg'];
            $isFlat = (int) $r['is_flat'];
            $minKgVal = (float) $r['min_kg_val'];

            if ($isFlat) {
                $totalCost = $rate;
                $formula = 'Tarif Flat';
            } else {
                $chargeableWeight = max($weight, $minKgVal > 0 ? $minKgVal : 1);
                $totalCost = $chargeableWeight * $rate;
                $formula = "{$chargeableWeight} kg × Rp ".number_format($rate, 0, ',', '.');
            }

            $leadTime = trim($r['lead_time'] ?? '') ?: 'Reguler';
            $key = "{$exp}|{$srv}";

            if (! isset($grouped[$key]) || $totalCost < $grouped[$key]['total_cost']) {
                $grouped[$key] = [
                    'expedition' => $exp,
                    'service' => $srv,
                    'min_kg' => $r['min_kg'] ?: '1 KG',
                    'min_kg_val' => $minKgVal,
                    'is_flat' => (bool) $isFlat,
                    'rate_per_kg' => $rate,
                    'total_cost' => $totalCost,
                    'formula' => $formula,
                    'lead_time' => $leadTime,
                    'notes' => $r['notes'] ?? '',
                    'districts' => ! empty($r['destination_district']) ? [$r['destination_district']] : [],
                    'is_recommended' => false,
                ];
            } else {
                if (! empty($r['destination_district']) && ! in_array($r['destination_district'], $grouped[$key]['districts'])) {
                    $grouped[$key]['districts'][] = $r['destination_district'];
                }
            }
        }

        // Sort all by total_cost ASC, then rate_per_kg
        $all = array_values($grouped);
        usort($all, function ($a, $b) {
            if ($a['total_cost'] == $b['total_cost']) {
                return $a['rate_per_kg'] <=> $b['rate_per_kg'];
            }

            return $a['total_cost'] <=> $b['total_cost'];
        });

        // Prioritize distinct expeditions first so the user compares "mending ekspedisi yang mana"
        $seenExpeditions = [];
        $topUniqueExpeditions = [];
        $alternativeServices = [];

        foreach ($all as $item) {
            if (! isset($seenExpeditions[$item['expedition']])) {
                $seenExpeditions[$item['expedition']] = true;
                $topUniqueExpeditions[] = $item;
            } else {
                $alternativeServices[] = $item;
            }
        }

        $results = array_merge($topUniqueExpeditions, $alternativeServices);

        // Limit to max 5 recommendations (or specified limit)
        if ($limit > 0) {
            $results = array_slice($results, 0, $limit);
        }

        // Multiple recommendation options (Up to 5 options)
        if (! empty($results)) {
            $totalCount = count($results);

            foreach ($results as $index => &$item) {
                $item['is_recommended'] = true;

                if ($index === 0) {
                    $item['recommendation_label'] = $totalCount > 1 ? 'Rekomendasi 1 (Termurah)' : 'Rekomendasi Utama';
                    $item['recommendation_badge'] = 'emerald';
                } elseif ($index === 1) {
                    $item['recommendation_label'] = 'Rekomendasi 2';
                    $item['recommendation_badge'] = 'sky';
                } elseif ($index === 2) {
                    $item['recommendation_label'] = 'Rekomendasi 3';
                    $item['recommendation_badge'] = 'indigo';
                } elseif ($index === 3) {
                    $item['recommendation_label'] = 'Rekomendasi 4';
                    $item['recommendation_badge'] = 'purple';
                } elseif ($index === 4) {
                    $item['recommendation_label'] = 'Rekomendasi 5';
                    $item['recommendation_badge'] = 'amber';
                } else {
                    $item['is_recommended'] = false;
                    $item['recommendation_label'] = null;
                    $item['recommendation_badge'] = null;
                }
            }
        }

        return $results;
    }
}

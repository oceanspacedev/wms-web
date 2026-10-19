<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Expedition;
use App\Models\ExpeditionRateCard;
use App\Support\Rupiah;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use XMLReader;
use ZipArchive;

class RateCardImportService
{
    /**
     * Import rate cards from an uploaded CSV or XLSX file with high-speed streaming.
     *
     * @return array{
     *     success: bool,
     *     total_rows: int,
     *     unique_records_count: int,
     *     imported_count: int,
     *     updated_count: int,
     *     new_expeditions_count: int,
     *     message: string
     * }
     */
    public function importFile(
        string $filePath,
        string $mode = 'upsert',
        float $defaultInsurancePercent = 0.2
    ): array {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '1024M');

        if (! file_exists($filePath)) {
            throw new Exception("File tidak ditemukan: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'xlsx') {
            return $this->processXlsxStreaming($filePath, $mode, $defaultInsurancePercent);
        }

        return $this->processCsvStreaming($filePath, $mode, $defaultInsurancePercent);
    }

    /**
     * Stream and process XLSX without loading massive DOM in memory.
     *
     * @return array{
     *     success: bool,
     *     total_rows: int,
     *     unique_records_count: int,
     *     imported_count: int,
     *     updated_count: int,
     *     new_expeditions_count: int,
     *     message: string
     * }
     */
    protected function processXlsxStreaming(
        string $filePath,
        string $mode,
        float $defaultInsurancePercent
    ): array {
        $zip = new ZipArchive;
        if ($zip->open($filePath, ZipArchive::RDONLY) !== true) {
            throw new Exception('Gagal membuka file Excel (.xlsx). Pastikan file tidak rusak.');
        }

        // 1. Read shared strings with fast XMLReader streaming
        $sharedStrings = [];
        $ssStream = $zip->getStream('xl/sharedStrings.xml');
        if ($ssStream) {
            $reader = new XMLReader;
            $reader->xml(stream_get_contents($ssStream));
            fclose($ssStream);

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                    $siXml = simplexml_load_string($reader->readOuterXml());
                    if ($siXml !== false) {
                        if (isset($siXml->t)) {
                            $sharedStrings[] = (string) $siXml->t;
                        } elseif (isset($siXml->r)) {
                            $text = '';
                            foreach ($siXml->r as $r) {
                                $text .= (string) $r->t;
                            }
                            $sharedStrings[] = $text;
                        } else {
                            $sharedStrings[] = '';
                        }
                    }
                }
            }
            $reader->close();
        }

        // 2. Extract sheet1.xml to temp file for streaming
        $sheetStream = $zip->getStream('xl/worksheets/sheet1.xml');
        if (! $sheetStream) {
            $zip->close();
            throw new Exception('Lembar kerja sheet1.xml tidak ditemukan di dalam file Excel.');
        }

        $tempSheetFile = storage_path('app/temp_import_sheet_'.uniqid().'.xml');
        $tempFp = fopen($tempSheetFile, 'w');
        stream_copy_to_stream($sheetStream, $tempFp);
        fclose($tempFp);
        fclose($sheetStream);
        $zip->close();

        $reader = new XMLReader;
        $reader->open($tempSheetFile);

        $totalRows = 0;
        $columnMap = [];
        $headerFound = false;

        // In-memory deduplicated records
        $uniqueRecords = [];
        $expeditionCache = $this->loadExpeditionCache();
        $initialExpeditionCount = count($expeditionCache);

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'row') {
                    $totalRows++;
                    $rowXml = simplexml_load_string($reader->readOuterXml());
                    if ($rowXml === false) {
                        continue;
                    }

                    $cells = $this->parseRowCells($rowXml, $sharedStrings);

                    if (! $headerFound) {
                        $map = $this->mapHeaderColumns($cells);
                        if (isset($map['expedition']) && (isset($map['origin']) || isset($map['destination_city']))) {
                            $columnMap = $map;
                            $headerFound = true;
                        }

                        continue;
                    }

                    $this->extractRecordIntoMap(
                        $cells,
                        $columnMap,
                        $uniqueRecords,
                        $expeditionCache,
                        $defaultInsurancePercent
                    );
                }
            }
        } finally {
            $reader->close();
            if (file_exists($tempSheetFile)) {
                @unlink($tempSheetFile);
            }
        }

        if (! $headerFound || empty($columnMap)) {
            throw new Exception('Header kolom tidak dikenali. Pastikan file memiliki kolom: Expedisi, Origin/Asal, Kota Tujuan, Price/Tarif.');
        }

        return $this->bulkPersistRateCards(
            $uniqueRecords,
            $totalRows - 1,
            $mode,
            $initialExpeditionCount,
            count($expeditionCache)
        );
    }

    /**
     * Stream and process CSV file with in-memory deduplication.
     *
     * @return array{
     *     success: bool,
     *     total_rows: int,
     *     unique_records_count: int,
     *     imported_count: int,
     *     updated_count: int,
     *     new_expeditions_count: int,
     *     message: string
     * }
     */
    protected function processCsvStreaming(
        string $filePath,
        string $mode,
        float $defaultInsurancePercent
    ): array {
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new Exception('Gagal membuka file CSV.');
        }

        // Detect delimiter
        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = ',';
        if ($firstLine !== false) {
            $semicolonCount = substr_count($firstLine, ';');
            $commaCount = substr_count($firstLine, ',');
            $tabCount = substr_count($firstLine, "\t");

            if ($semicolonCount > $commaCount && $semicolonCount > $tabCount) {
                $delimiter = ';';
            } elseif ($tabCount > $commaCount && $tabCount > $semicolonCount) {
                $delimiter = "\t";
            }
        }

        $totalRows = 0;
        $columnMap = [];
        $headerFound = false;
        $uniqueRecords = [];
        $expeditionCache = $this->loadExpeditionCache();
        $initialExpeditionCount = count($expeditionCache);

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $cleanRow = array_map(fn ($val) => trim((string) $val), $row);
            if (empty(array_filter($cleanRow, fn ($v) => $v !== ''))) {
                continue;
            }

            $totalRows++;

            if (! $headerFound) {
                $map = $this->mapHeaderColumns($cleanRow);
                if (isset($map['expedition']) && (isset($map['origin']) || isset($map['destination_city']))) {
                    $columnMap = $map;
                    $headerFound = true;
                }

                continue;
            }

            $this->extractRecordIntoMap(
                $cleanRow,
                $columnMap,
                $uniqueRecords,
                $expeditionCache,
                $defaultInsurancePercent
            );
        }

        fclose($handle);

        if (! $headerFound || empty($columnMap)) {
            throw new Exception('Header kolom tidak dikenali. Pastikan file memiliki kolom: Expedisi, Origin/Asal, Kota Tujuan, Price/Tarif.');
        }

        return $this->bulkPersistRateCards(
            $uniqueRecords,
            $totalRows - 1,
            $mode,
            $initialExpeditionCount,
            count($expeditionCache)
        );
    }

    /**
     * Parse row XML into an array of string values by cell column index.
     *
     * @param  array<int, string>  $sharedStrings
     * @return array<int, string>
     */
    protected function parseRowCells(\SimpleXMLElement $rowXml, array $sharedStrings): array
    {
        $cells = [];
        $colIdx = 0;

        foreach ($rowXml->c as $cell) {
            $cellRef = (string) $cell['r'];
            preg_match('/^([A-Z]+)(\d+)$/', $cellRef, $matches);
            $colLetters = $matches[1] ?? 'A';

            $targetCol = 0;
            $len = strlen($colLetters);
            for ($l = 0; $l < $len; $l++) {
                $targetCol = $targetCol * 26 + (ord($colLetters[$l]) - 64);
            }
            $targetCol -= 1;

            while ($colIdx < $targetCol) {
                $cells[$colIdx] = '';
                $colIdx++;
            }

            $type = (string) $cell['t'];
            $val = isset($cell->v) ? (string) $cell->v : '';

            if ($type === 's') {
                $val = $sharedStrings[(int) $val] ?? '';
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $val = (string) $cell->is->t;
            }

            $cells[$colIdx] = trim((string) $val);
            $colIdx++;
        }

        return $cells;
    }

    /**
     * Extract and normalize a data row into the in-memory unique records map.
     *
     * @param  array<int, string>  $cells
     * @param  array<string, int>  $columnMap
     * @param  array<string, array<string, mixed>>  $uniqueRecords
     * @param  array<string, int>  $expeditionCache
     */
    protected function extractRecordIntoMap(
        array $cells,
        array $columnMap,
        array &$uniqueRecords,
        array &$expeditionCache,
        float $defaultInsurancePercent
    ): void {
        $expName = trim($this->getVal($cells, $columnMap, 'expedition'));
        $origin = strtoupper(trim($this->getVal($cells, $columnMap, 'origin')));
        $destinationCity = strtoupper(trim($this->getVal($cells, $columnMap, 'destination_city')));
        $destinationDistrict = strtoupper(trim($this->getVal($cells, $columnMap, 'destination_district')));
        $province = strtoupper(trim($this->getVal($cells, $columnMap, 'province')));
        $rawService = strtoupper(trim($this->getVal($cells, $columnMap, 'service')));
        $rawMinKg = trim($this->getVal($cells, $columnMap, 'min_kg'));
        $rawPrice = trim($this->getVal($cells, $columnMap, 'rate_per_kg'));
        $rawLeadTime = trim($this->getVal($cells, $columnMap, 'lead_time'));
        $notes = trim($this->getVal($cells, $columnMap, 'notes'));

        if (empty($expName) || empty($origin) || empty($destinationCity)) {
            return;
        }

        $price = $this->parsePrice($rawPrice);
        if ($price <= 0) {
            return;
        }

        $expId = $this->resolveExpeditionId($expName, $expeditionCache);
        $serviceType = $this->normalizeServiceType($rawService);
        $minKg = $this->parseMinKg($rawMinKg);
        $slaDays = $this->cleanSlaDays($rawLeadTime);

        // Deduplication key
        $key = "{$expId}|{$origin}|{$destinationCity}|{$destinationDistrict}|{$serviceType}|{$minKg}";

        $uniqueRecords[$key] = [
            'expedition_id' => $expId,
            'origin_depo' => $origin,
            'destination_city' => $destinationCity,
            'destination_district' => ! empty($destinationDistrict) ? $destinationDistrict : null,
            'province' => ! empty($province) ? $province : null,
            'service_type' => $serviceType,
            'rate_per_kg' => $price,
            'min_kg' => $minKg,
            'insurance_rate_percent' => $defaultInsurancePercent,
            'sla_days' => $slaDays,
            'notes' => ! empty($notes) ? $notes : null,
            'is_active' => 1,
        ];
    }

    /**
     * Bulk persist unique rate cards into MySQL database with chunked batch inserts.
     *
     * @param  array<string, array<string, mixed>>  $uniqueRecords
     * @return array{
     *     success: bool,
     *     total_rows: int,
     *     unique_records_count: int,
     *     imported_count: int,
     *     updated_count: int,
     *     new_expeditions_count: int,
     *     message: string
     * }
     */
    protected function bulkPersistRateCards(
        array $uniqueRecords,
        int $totalRows,
        string $mode,
        int $initialExpeditionCount,
        int $finalExpeditionCount
    ): array {
        $uniqueCount = count($uniqueRecords);
        if ($uniqueCount === 0) {
            throw new Exception('Tidak ada baris data tarif yang valid untuk diimport.');
        }

        $now = now()->toDateTimeString();
        $importedCount = 0;
        $updatedCount = 0;

        DB::disableQueryLog();
        DB::beginTransaction();

        $isMysql = DB::getDriverName() === 'mysql';

        try {
            if ($isMysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            }

            if ($mode === 'replace') {
                ExpeditionRateCard::query()->delete();

                $insertBatch = [];
                foreach ($uniqueRecords as $data) {
                    $insertBatch[] = array_merge($data, [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if (count($insertBatch) >= 2000) {
                        DB::table('expedition_rate_cards')->insert($insertBatch);
                        $insertBatch = [];
                    }
                }

                if (! empty($insertBatch)) {
                    DB::table('expedition_rate_cards')->insert($insertBatch);
                }

                $importedCount = $uniqueCount;
            } else {
                // Upsert mode: map existing records with normalized keys
                $existing = ExpeditionRateCard::select(
                    'id',
                    'expedition_id',
                    'origin_depo',
                    'destination_city',
                    'destination_district',
                    'service_type',
                    'min_kg',
                    'rate_per_kg',
                    'sla_days',
                    'notes',
                    'province',
                    'insurance_rate_percent'
                )->get();

                $existingMap = [];
                foreach ($existing as $erc) {
                    $minKgFloat = (float) $erc->min_kg;
                    $dist = strtoupper(trim((string) ($erc->destination_district ?? '')));
                    $k = "{$erc->expedition_id}|{$erc->origin_depo}|{$erc->destination_city}|{$dist}|{$erc->service_type}|{$minKgFloat}";
                    $existingMap[$k] = $erc;
                }

                $insertBatch = [];
                foreach ($uniqueRecords as $key => $data) {
                    if (isset($existingMap[$key])) {
                        $existingErc = $existingMap[$key];
                        $hasChanged = (float) $existingErc->rate_per_kg !== (float) $data['rate_per_kg']
                            || (string) ($existingErc->sla_days ?? '') !== (string) ($data['sla_days'] ?? '')
                            || (string) ($existingErc->notes ?? '') !== (string) ($data['notes'] ?? '')
                            || (string) ($existingErc->province ?? '') !== (string) ($data['province'] ?? '')
                            || (float) $existingErc->insurance_rate_percent !== (float) $data['insurance_rate_percent'];

                        if ($hasChanged) {
                            DB::table('expedition_rate_cards')
                                ->where('id', $existingErc->id)
                                ->update(array_merge($data, ['updated_at' => $now]));
                            $updatedCount++;
                        }
                    } else {
                        $insertBatch[] = array_merge($data, [
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $importedCount++;

                        if (count($insertBatch) >= 2000) {
                            DB::table('expedition_rate_cards')->insert($insertBatch);
                            $insertBatch = [];
                        }
                    }
                }

                if (! empty($insertBatch)) {
                    DB::table('expedition_rate_cards')->insert($insertBatch);
                }
            }

            if ($isMysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            }
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            if ($isMysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            }
            Log::error('Bulk import rate cards error: '.$e->getMessage());
            throw $e;
        }

        $newExpeditionsCount = max(0, $finalExpeditionCount - $initialExpeditionCount);

        return [
            'success' => true,
            'total_rows' => $totalRows,
            'unique_records_count' => $uniqueCount,
            'imported_count' => $importedCount,
            'updated_count' => $updatedCount,
            'new_expeditions_count' => $newExpeditionsCount,
            'message' => "Berhasil memproses {$totalRows} baris ({$uniqueCount} tarif unik). Baru: {$importedCount}, Diupdate: {$updatedCount}, Ekspedisi Baru: {$newExpeditionsCount}.",
        ];
    }

    /**
     * Pre-load all expeditions in memory cache.
     *
     * @return array<string, int>
     */
    protected function loadExpeditionCache(): array
    {
        $all = Expedition::select('id', 'name')->get();
        $cache = [];

        foreach ($all as $exp) {
            $upper = strtoupper(trim((string) $exp->name));
            $cache[$upper] = (int) $exp->id;
        }

        return $cache;
    }

    /**
     * Resolve expedition ID from in-memory cache or create once.
     *
     * @param  array<string, int>  $cache
     */
    protected function resolveExpeditionId(string $name, array &$cache): int
    {
        $upper = strtoupper(trim($name));
        if (isset($cache[$upper])) {
            return $cache[$upper];
        }

        $code = strtoupper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'EXP', 10, ''));
        if (Expedition::where('code', $code)->exists()) {
            $code = strtoupper(Str::limit($code, 8, '').rand(10, 99));
        }

        $exp = Expedition::create([
            'name' => trim($name),
            'code' => $code,
            'is_active' => true,
        ]);

        $cache[$upper] = (int) $exp->id;

        return (int) $exp->id;
    }

    /**
     * Map row column index to known schema attributes.
     *
     * @param  array<int, string>  $headerRow
     * @return array<string, int>
     */
    protected function mapHeaderColumns(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $idx => $col) {
            $clean = strtolower(trim((string) $col));
            $clean = preg_replace('/[^a-z0-9\/]/', '', $clean) ?? '';

            if (preg_match('/expedi|ekspedi|vendor|kurir/i', $clean)) {
                $map['expedition'] = $idx;
            } elseif (preg_match('/^origin$|^asal$|^kotasal$/i', $clean)) {
                $map['origin'] = $idx;
            } elseif (preg_match('/prov/i', $clean)) {
                $map['province'] = $idx;
            } elseif (preg_match('/kotatujuan|destinationcity|tujuan/i', $clean) && ! isset($map['destination_city'])) {
                $map['destination_city'] = $idx;
            } elseif (preg_match('/kecamatan|district/i', $clean)) {
                $map['destination_district'] = $idx;
            } elseif (preg_match('/\/kg|minkg|minberat|beratmin/i', $clean)) {
                $map['min_kg'] = $idx;
            } elseif (preg_match('/price|harga|tarif|rate/i', $clean)) {
                $map['rate_per_kg'] = $idx;
            } elseif (preg_match('/leadtime|sla|hari|estimasi/i', $clean)) {
                $map['lead_time'] = $idx;
            } elseif (preg_match('/service|layanan|jalur/i', $clean)) {
                $map['service'] = $idx;
            } elseif (preg_match('/ket|keterangan|notes|catatan/i', $clean)) {
                $map['notes'] = $idx;
            }
        }

        if (! isset($map['destination_city']) && isset($map['destination_district'])) {
            $map['destination_city'] = $map['destination_district'];
            unset($map['destination_district']);
        }

        return $map;
    }

    /**
     * Get cell value safely by mapped column.
     *
     * @param  array<int, string>  $cells
     * @param  array<string, int>  $map
     */
    protected function getVal(array $cells, array $map, string $colName): string
    {
        if (! isset($map[$colName])) {
            return '';
        }

        $idx = $map[$colName];

        return isset($cells[$idx]) ? trim((string) $cells[$idx]) : '';
    }

    /**
     * Normalize service type to standard DARAT, UDARA, LAUT.
     */
    protected function normalizeServiceType(string $service): string
    {
        $s = strtoupper(trim($service));

        if (str_contains($s, 'UDARA') || str_contains($s, 'AIR') || str_contains($s, 'PESAWAT')) {
            return 'UDARA';
        }

        if (str_contains($s, 'LAUT') || str_contains($s, 'SEA') || str_contains($s, 'KAPAL')) {
            return 'LAUT';
        }

        return 'DARAT';
    }

    /**
     * Parse minimum KG from string like "10 KG", "FLAT", "1 KG".
     */
    protected function parseMinKg(string $str): float
    {
        $upper = strtoupper(trim($str));

        if (in_array($upper, ['FLAT', '/COLLY', 'DOC', '/KUBIKASI'])) {
            return 0.0;
        }

        if (preg_match('/(\d+(?:\.\d+)?)/', $upper, $matches)) {
            return (float) $matches[1];
        }

        return 1.0;
    }

    /**
     * Parse price string into numeric float (e.g. "Rp 2.900" -> 2900, sel angka "1500.0" -> 1500).
     */
    protected function parsePrice(string $priceStr): float
    {
        return Rupiah::parse($priceStr);
    }

    /**
     * Clean SLA / Lead Time string (e.g. "1 HARI" -> "1", "1-3 HARI" -> "1-3").
     */
    protected function cleanSlaDays(string $str): ?string
    {
        $clean = trim(preg_replace('/hari/i', '', $str));

        return ! empty($clean) ? $clean : null;
    }
}

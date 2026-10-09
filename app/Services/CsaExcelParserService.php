<?php

namespace App\Services;

use App\Models\WarehouseMapping;
use Exception;

class CsaExcelParserService
{
    public function __construct(private XlsxReader $reader) {}

    /**
     * Parse a CSA sales report or a depot shipment workbook into shipments.
     *
     * @return array{
     *     total_raw_rows: int,
     *     total_shipments: int,
     *     by_sheet: array<string, array{total_sj: int, total_qty: int, total_nominal: float}>,
     *     shipments: array<int, array<string, mixed>>
     * }
     */
    public function parseAndAggregate(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        $sharedStrings = $this->reader->sharedStrings($filePath);
        $sheets = $this->reader->sheets($filePath);

        $salesSheet = null;
        $depotSheets = [];

        foreach ($sheets as $sheetName => $sheetPath) {
            $kind = $this->classifySheet($filePath, $sheetPath, $sharedStrings);

            if ($kind === 'sales' && $salesSheet === null) {
                $salesSheet = $sheetPath;
            }

            if ($kind === 'depot') {
                $depotSheets[$sheetName] = $sheetPath;
            }
        }

        if ($salesSheet !== null) {
            return $this->parseSalesSheet($filePath, $salesSheet, $sharedStrings);
        }

        if ($depotSheets !== []) {
            return $this->parseDepotSheets($filePath, $depotSheets, $sharedStrings);
        }

        throw new Exception('Format Excel tidak dikenali sebagai laporan penjualan CSA atau laporan pengiriman depo');
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    protected function classifySheet(string $filePath, string $sheetPath, array $sharedStrings): string
    {
        $seen = 0;

        foreach ($this->reader->iterateRows($filePath, $sheetPath, $sharedStrings) as $cells) {
            $seen++;
            $text = strtolower(implode(' ', $cells));

            if (str_contains($text, 'nama barang') && str_contains($text, 'nomor sj')) {
                return 'sales';
            }

            if ((str_contains($text, 'nomor sj') || str_contains($text, 'surat jalan'))
                && (str_contains($text, 'depo') || str_contains($text, 'berat'))) {
                return 'depot';
            }

            if (str_contains($text, 'shipment') && (str_contains($text, 'tariff') || str_contains($text, 'weight') || str_contains($text, 'tarif'))) {
                return 'invoice';
            }

            if ($seen >= 20) {
                break;
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array{
     *     total_raw_rows: int,
     *     total_shipments: int,
     *     by_sheet: array<string, array{total_sj: int, total_qty: int, total_nominal: float}>,
     *     shipments: array<int, array<string, mixed>>
     * }
     */
    protected function parseSalesSheet(string $filePath, string $sheetPath, array $sharedStrings): array
    {
        $warehouseMappings = WarehouseMapping::query()
            ->where('is_active', true)
            ->pluck('target_sheet', 'csa_code')
            ->toArray();

        $headerMap = [];
        $badanUsaha = 'PT. MEDIA SELULAR INDONESIA';
        $rawRowCount = 0;
        $groupedShipments = [];

        foreach ($this->reader->iterateRows($filePath, $sheetPath, $sharedStrings) as $cells) {
            $rowNumber = (int) ($cells['_row'] ?? 0);

            if ($headerMap === []) {
                if ($this->isSalesHeader($cells)) {
                    $headerMap = $this->buildHeaderMap($cells);

                    continue;
                }

                $title = trim($cells['A'] ?? '');
                if ($rowNumber < 8 && preg_match('/\b(PT|CV)\b/i', $title) === 1) {
                    $badanUsaha = $title;
                }

                continue;
            }

            $rawRowCount++;
            $this->accumulateSalesRow($cells, $headerMap, $groupedShipments, $warehouseMappings, $badanUsaha);
        }

        if ($headerMap === []) {
            throw new Exception('Header laporan penjualan CSA tidak ditemukan');
        }

        return $this->finalize($groupedShipments, $rawRowCount);
    }

    /**
     * @param  array<string, string>  $depotSheets
     * @param  array<int, string>  $sharedStrings
     * @return array{
     *     total_raw_rows: int,
     *     total_shipments: int,
     *     by_sheet: array<string, array{total_sj: int, total_qty: int, total_nominal: float}>,
     *     shipments: array<int, array<string, mixed>>
     * }
     */
    protected function parseDepotSheets(string $filePath, array $depotSheets, array $sharedStrings): array
    {
        $rawRowCount = 0;
        $groupedShipments = [];

        foreach ($depotSheets as $sheetName => $sheetPath) {
            $headerMap = [];

            foreach ($this->reader->iterateRows($filePath, $sheetPath, $sharedStrings) as $cells) {
                if ($headerMap === []) {
                    if ($this->rowLooksLikeDepotHeader($cells)) {
                        $headerMap = $this->buildDepotHeaderMap($cells);
                    }

                    continue;
                }

                $rawRowCount++;
                $this->accumulateDepotRow($cells, $headerMap, $groupedShipments, $sheetName);
            }
        }

        return $this->finalize($groupedShipments, $rawRowCount);
    }

    /**
     * @param  array<string, array<string, mixed>>  $groupedShipments
     * @return array{
     *     total_raw_rows: int,
     *     total_shipments: int,
     *     by_sheet: array<string, array{total_sj: int, total_qty: int, total_nominal: float}>,
     *     shipments: array<int, array<string, mixed>>
     * }
     */
    protected function finalize(array $groupedShipments, int $rawRowCount): array
    {
        $shipmentsList = [];
        $bySheet = [];

        foreach ($groupedShipments as $shipment) {
            unset($shipment['items_count']);
            $sheet = $shipment['target_sheet'];

            if (! isset($bySheet[$sheet])) {
                $bySheet[$sheet] = [
                    'total_sj' => 0,
                    'total_qty' => 0,
                    'total_nominal' => 0.0,
                ];
            }

            $bySheet[$sheet]['total_sj']++;
            $bySheet[$sheet]['total_qty'] += $shipment['qty_unit'];
            $bySheet[$sheet]['total_nominal'] += $shipment['total_nominal_sj'];
            $shipmentsList[] = $shipment;
        }

        return [
            'total_raw_rows' => $rawRowCount,
            'total_shipments' => count($shipmentsList),
            'by_sheet' => $bySheet,
            'shipments' => $shipmentsList,
        ];
    }

    /**
     * @param  array<string, string>  $cells
     */
    protected function isSalesHeader(array $cells): bool
    {
        $text = strtolower(implode(' ', $cells));

        return str_contains($text, 'nomor sj') && str_contains($text, 'nama barang');
    }

    /**
     * @param  array<string, string>  $cells
     */
    protected function rowLooksLikeDepotHeader(array $cells): bool
    {
        $text = strtolower(implode(' ', $cells));

        return (str_contains($text, 'nomor sj') || str_contains($text, 'surat jalan'))
            && (str_contains($text, 'depo') || str_contains($text, 'berat'));
    }

    /**
     * @param  array<string, string>  $headerCells
     * @return array<string, string>
     */
    protected function buildHeaderMap(array $headerCells): array
    {
        $labels = $this->labels($headerCells);
        $gudangColumn = $this->findColumn($labels, ['gudang'], true);
        $kodeGudang = null;

        if ($gudangColumn !== null) {
            $gudangIndex = $this->columnIndex($gudangColumn);

            foreach ($labels as $column => $label) {
                if ($label === 'kode' && $this->columnIndex($column) === $gudangIndex - 1) {
                    $kodeGudang = $column;
                }
            }
        }

        $map = [
            'tanggal' => $this->findColumn($labels, ['tanggal'], true),
            'no_trans' => $this->findColumn($labels, ['no trans']),
            'no_so' => $this->findColumn($labels, ['nomor so']),
            'no_faktur' => $this->findColumn($labels, ['no faktur']),
            'no_sj' => $this->findColumn($labels, ['nomor sj']),
            'kode_gudang' => $kodeGudang,
            'nama_gudang' => $gudangColumn,
            'customer' => $this->findColumn($labels, ['customer'], true),
            'resi' => $this->findColumn($labels, ['nomor resi', 'resi']),
            'reff_note' => $this->findColumn($labels, ['ref.note', 'ref note', 'reffnote']),
            'nama_barang' => $this->findColumn($labels, ['nama barang']),
            'brand' => $this->findColumn($labels, ['family']) ?? $this->findColumn($labels, ['brand']),
            'qty' => $this->findColumn($labels, ['qty'], true),
            'nominal' => $this->findColumn($labels, ['jumlah'], true),
        ];

        foreach (['no_sj', 'qty', 'nominal', 'nama_gudang', 'customer'] as $required) {
            if ($map[$required] === null) {
                throw new Exception("Kolom {$required} tidak ditemukan pada laporan penjualan CSA");
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $headerCells
     * @return array<string, string>
     */
    protected function buildDepotHeaderMap(array $headerCells): array
    {
        $map = [];

        foreach ($this->labels($headerCells) as $column => $label) {
            $field = match (true) {
                str_contains($label, 'tanggal order') => 'tanggal_order',
                str_contains($label, 'tanggal kirim') => 'tanggal_kirim',
                str_contains($label, 'badan usaha') => 'badan_usaha',
                str_contains($label, 'depo') || str_contains($label, 'warehouse') => 'depo',
                str_contains($label, 'tujuan') || str_contains($label, 'dealer') => 'tujuan_dealer',
                str_contains($label, 'alamat') => 'alamat_kirim',
                str_contains($label, 'nama kota') => 'nama_kota',
                str_contains($label, 'brand') => 'brand',
                str_contains($label, 'nomor sj') || str_contains($label, 'surat jalan') => 'no_sj',
                str_contains($label, 'nominal') || str_contains($label, 'value nota') => 'total_nominal_sj',
                str_contains($label, 'reff') => 'reff_note',
                str_contains($label, 'qty unit') => 'qty_unit',
                str_contains($label, 'koli') => 'qty_koli',
                str_contains($label, 'berat') => 'berat',
                str_contains($label, 'ketentuan') => 'ketentuan_biaya_kirim',
                str_contains($label, 'ekspedisi') || str_contains($label, 'expedisi') => 'nama_ekspedisi',
                str_contains($label, 'resi') || str_contains($label, 'awb') => 'no_resi_awb',
                str_contains($label, 'biaya kirim') => 'biaya_kirim',
                str_contains($label, 'status pembayaran') => 'status_pembayaran',
                str_contains($label, 'status pengiriman') => 'status_pengiriman',
                str_contains($label, 'diterima') => 'tanggal_diterima',
                str_contains($label, 'isi unit') => 'ket_isi_unit',
                default => null,
            };

            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $column;
            }
        }

        if (! isset($map['no_sj']) && ! isset($map['no_resi_awb'])) {
            throw new Exception('Kolom Nomor SJ atau No Resi tidak ditemukan pada laporan pengiriman depo');
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, string>  $map
     * @param  array<string, array<string, mixed>>  $grouped
     * @param  array<string, string>  $warehouseMappings
     */
    protected function accumulateSalesRow(array $cells, array $map, array &$grouped, array $warehouseMappings, string $badanUsaha): void
    {
        $noSj = $this->reader->normalizeIdentifier($this->cell($cells, $map, 'no_sj'));
        $noTrans = $this->reader->normalizeIdentifier($this->cell($cells, $map, 'no_trans'));
        $noFaktur = $this->reader->normalizeIdentifier($this->cell($cells, $map, 'no_faktur'));
        $key = $noSj !== '' ? $noSj : ($noFaktur !== '' ? $noFaktur : $noTrans);

        if ($key === '') {
            return;
        }

        $kodeGudang = trim($this->cell($cells, $map, 'kode_gudang'));
        $namaGudang = trim($this->cell($cells, $map, 'nama_gudang'));
        $targetSheet = $warehouseMappings[$kodeGudang] ?? $this->guessTargetSheet($namaGudang, $kodeGudang);
        $qty = (int) ($this->cell($cells, $map, 'qty') !== '' ? $this->cell($cells, $map, 'qty') : 0);
        $nominal = (float) ($this->cell($cells, $map, 'nominal') !== '' ? $this->cell($cells, $map, 'nominal') : 0);
        $tanggalKirim = $this->excelDateToDateString($this->cell($cells, $map, 'tanggal'));
        $customer = trim($this->cell($cells, $map, 'customer'));
        $brand = trim($this->cell($cells, $map, 'brand'));
        $reffNote = trim($this->cell($cells, $map, 'reff_note'));
        $namaBarang = trim($this->cell($cells, $map, 'nama_barang'));
        [$namaEkspedisi, $noResiAwb] = $this->resolveEkspedisiAndResi($this->cell($cells, $map, 'resi'));

        if (! isset($grouped[$key])) {
            $grouped[$key] = $this->blankShipment($key, $noSj, [
                'no_trans' => $noTrans !== '' ? $noTrans : null,
                'no_so' => $this->reader->normalizeIdentifier($this->cell($cells, $map, 'no_so')) ?: null,
                'tanggal_order' => $tanggalKirim,
                'tanggal_kirim' => $tanggalKirim,
                'badan_usaha' => $badanUsaha,
                'kode_gudang' => $kodeGudang !== '' ? $kodeGudang : $targetSheet,
                'nama_gudang' => $namaGudang,
                'target_sheet' => $targetSheet,
                'tujuan_dealer' => $customer,
                'brand' => $brand,
                'reff_note' => $reffNote,
                'nama_ekspedisi' => $namaEkspedisi,
                'no_resi_awb' => $noResiAwb,
            ]);
        } else {
            if ($grouped[$key]['nama_ekspedisi'] === null && $namaEkspedisi !== null) {
                $grouped[$key]['nama_ekspedisi'] = $namaEkspedisi;
            }
            if ($grouped[$key]['no_resi_awb'] === null && $noResiAwb !== null) {
                $grouped[$key]['no_resi_awb'] = $noResiAwb;
            }
        }

        $grouped[$key]['qty_unit'] += $qty;
        $grouped[$key]['total_nominal_sj'] += $nominal;

        if ($namaBarang !== '') {
            $shortItem = preg_replace('/\s+-\s+.*$/', '', $namaBarang) ?? $namaBarang;
            $grouped[$key]['items_count'][$shortItem] = ($grouped[$key]['items_count'][$shortItem] ?? 0) + $qty;
        }

        $summaryParts = [];
        foreach ($grouped[$key]['items_count'] as $item => $count) {
            $summaryParts[] = "{$count}x {$item}";
        }
        $grouped[$key]['ket_isi_unit'] = implode(', ', array_slice($summaryParts, 0, 3));
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, string>  $map
     * @param  array<string, array<string, mixed>>  $grouped
     */
    protected function accumulateDepotRow(array $cells, array $map, array &$grouped, string $sheetName): void
    {
        $noSj = $this->reader->normalizeIdentifier($this->cell($cells, $map, 'no_sj'));
        $namaEkspedisi = trim($this->cell($cells, $map, 'nama_ekspedisi'));
        $noResi = $this->reader->normalizeIdentifier($this->cell($cells, $map, 'no_resi_awb'));

        if ($namaEkspedisi === '' && $noResi !== '') {
            [$resolvedEksp, $resolvedResi] = $this->resolveEkspedisiAndResi($noResi);
            if ($resolvedEksp !== null) {
                $namaEkspedisi = $resolvedEksp;
                $noResi = $resolvedResi ?? '';
            }
        }

        $depo = trim($this->cell($cells, $map, 'depo'));
        $targetSheet = $depo !== '' ? $depo : $sheetName;
        $key = $targetSheet.'|'.($noSj !== '' ? $noSj : $noResi);

        if ($key === $targetSheet.'|') {
            return;
        }

        $qty = (int) ($this->cell($cells, $map, 'qty_unit') !== '' ? $this->cell($cells, $map, 'qty_unit') : 0);
        $koli = (int) ($this->cell($cells, $map, 'qty_koli') !== '' ? $this->cell($cells, $map, 'qty_koli') : 0);
        $berat = (float) ($this->cell($cells, $map, 'berat') !== '' ? $this->cell($cells, $map, 'berat') : 0);
        $nominal = (float) ($this->cell($cells, $map, 'total_nominal_sj') !== '' ? $this->cell($cells, $map, 'total_nominal_sj') : 0);
        $biaya = $this->cell($cells, $map, 'biaya_kirim');

        if (! isset($grouped[$key])) {
            $grouped[$key] = $this->blankShipment($noSj !== '' ? $noSj : $noResi, $noSj, [
                'tanggal_order' => $this->excelDateToDateString($this->cell($cells, $map, 'tanggal_order')),
                'tanggal_kirim' => $this->excelDateToDateString($this->cell($cells, $map, 'tanggal_kirim')),
                'badan_usaha' => trim($this->cell($cells, $map, 'badan_usaha')) ?: null,
                'kode_gudang' => $targetSheet,
                'nama_gudang' => $targetSheet,
                'target_sheet' => $targetSheet,
                'tujuan_dealer' => trim($this->cell($cells, $map, 'tujuan_dealer')) ?: null,
                'alamat_kirim' => trim($this->cell($cells, $map, 'alamat_kirim')) ?: null,
                'nama_kota' => trim($this->cell($cells, $map, 'nama_kota')) ?: null,
                'brand' => trim($this->cell($cells, $map, 'brand')) ?: null,
                'reff_note' => trim($this->cell($cells, $map, 'reff_note')) ?: null,
                'ketentuan_biaya_kirim' => trim($this->cell($cells, $map, 'ketentuan_biaya_kirim')) ?: null,
                'nama_ekspedisi' => $namaEkspedisi !== '' ? $namaEkspedisi : null,
                'no_resi_awb' => $noResi !== '' ? $noResi : null,
                'biaya_kirim' => $biaya !== '' && is_numeric($biaya) ? (float) $biaya : null,
                'status_pembayaran' => trim($this->cell($cells, $map, 'status_pembayaran')) ?: null,
                'status_pengiriman' => trim($this->cell($cells, $map, 'status_pengiriman')) ?: null,
                'ket_isi_unit' => trim($this->cell($cells, $map, 'ket_isi_unit')) ?: null,
            ]);
        }

        $grouped[$key]['qty_unit'] += $qty;
        $grouped[$key]['qty_koli'] += $koli;
        $grouped[$key]['berat'] += $berat;
        $grouped[$key]['total_nominal_sj'] += $nominal;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function blankShipment(string $key, string $noSj, array $overrides): array
    {
        return array_merge([
            'no_sj' => $noSj !== '' ? $noSj : $key,
            'no_trans' => null,
            'no_so' => null,
            'tanggal_order' => null,
            'tanggal_kirim' => null,
            'badan_usaha' => null,
            'kode_gudang' => 'LAINNYA',
            'nama_gudang' => null,
            'target_sheet' => 'LAINNYA',
            'tujuan_dealer' => null,
            'alamat_kirim' => null,
            'nama_kota' => null,
            'brand' => null,
            'reff_note' => null,
            'total_nominal_sj' => 0.0,
            'qty_unit' => 0,
            'qty_koli' => 0,
            'berat' => 0.0,
            'ketentuan_biaya_kirim' => null,
            'nama_ekspedisi' => null,
            'no_resi_awb' => null,
            'biaya_kirim' => null,
            'status_pembayaran' => null,
            'status_pengiriman' => null,
            'ket_isi_unit' => null,
            'items_count' => [],
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $cells
     * @return array<string, string>
     */
    protected function labels(array $cells): array
    {
        $labels = [];

        foreach ($cells as $column => $label) {
            if ($column === '_row') {
                continue;
            }

            $labels[$column] = strtolower(trim($label));
        }

        return $labels;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<int, string>  $needles
     */
    protected function findColumn(array $labels, array $needles, bool $exact = false): ?string
    {
        foreach ($labels as $column => $label) {
            foreach ($needles as $needle) {
                if ($exact ? $label === $needle : str_contains($label, $needle)) {
                    return $column;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, string>  $map
     */
    protected function cell(array $cells, array $map, string $field): string
    {
        $column = $map[$field] ?? null;

        if ($column === null) {
            return '';
        }

        return trim((string) ($cells[$column] ?? ''));
    }

    protected function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index;
    }

    protected function guessTargetSheet(string $namaGudang, string $kodeGudang): string
    {
        $clean = strtoupper($namaGudang);

        $known = [
            'CIREBON' => 'CIREBON',
            'BANDUNG' => 'BANDUNG',
            'PIK' => 'JAKARTA PIK',
            'ONLINE' => 'JAKARTA PIK',
            'JAKARTA' => 'JAKARTA PC',
            'PURWOKERTO' => 'PURWOKERTO',
            'SURABAYA' => 'SURABAYA',
            'SEMARANG' => 'SEMARANG',
            'MAKASSAR' => 'MAKASSAR',
            'MANADO' => 'MANADO',
            'PALU' => 'PALU',
            'PADANG' => 'PADANG',
            'PEKANBARU' => 'PEKANBARU',
            'PALEMBANG' => 'PALEMBANG',
            'MEDAN' => 'MEDAN',
            'JAMBI' => 'JAMBI',
            'BENGKULU' => 'BENGKULU',
            'LAMPUNG' => 'LAMPUNG',
            'RETUR' => 'RETUR',
            'REFUND' => 'RETUR',
        ];

        foreach ($known as $needle => $sheet) {
            if (str_contains($clean, $needle)) {
                return $sheet;
            }
        }

        return $kodeGudang !== '' ? $kodeGudang : 'LAINNYA';
    }

    protected function excelDateToDateString(mixed $excelDate): ?string
    {
        if ($excelDate === null || $excelDate === '') {
            return null;
        }

        if (is_numeric($excelDate)) {
            $days = (int) $excelDate;
            $timestamp = strtotime("1899-12-30 +{$days} days");

            return $timestamp ? date('Y-m-d', $timestamp) : null;
        }

        $date = date_create((string) $excelDate);

        return $date ? date_format($date, 'Y-m-d') : null;
    }

    /**
     * Parse raw string from Excel "Nomor Resi" column to intelligently separate Expedition and Tracking Number.
     *
     * @return array{0: ?string, 1: ?string} [nama_ekspedisi, no_resi_awb]
     */
    public function resolveEkspedisiAndResi(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '-' || $raw === '0') {
            return [null, null];
        }

        $upper = strtoupper($raw);

        // 1. Kurir internal
        $internalCouriers = ['MOCH ARIP', 'SUJAEDI', 'ANDY'];
        if (in_array($upper, $internalCouriers, true)) {
            return ['INTERNAL ('.$raw.')', null];
        }

        if ($upper === 'REFUND') {
            return ['REFUND', null];
        }

        if ($upper === 'CASH ONLINE') {
            return ['CASH ONLINE', null];
        }

        // 2. Awalan EXPEDISI / EKSPEDISI / EXP / EKSP
        if (preg_match('/^(EXPEDISI|EKSPEDISI|EXP|EKSP)\s+(.*)$/i', $raw, $matches)) {
            $nama = trim($matches[2]);
            $upperNama = strtoupper($nama);
            $canonical = match (true) {
                str_contains($upperNama, 'J&T') || str_contains($upperNama, 'JNT') => 'J&T Express',
                str_contains($upperNama, '21 EXPRESS') || str_contains($upperNama, '21 EXPRES') => '21 EXPRES',
                str_contains($upperNama, 'RAX') => 'RAX',
                str_contains($upperNama, 'KARYA MANDIRI') => 'KARYA MANDIRI',
                str_contains($upperNama, 'SENTRAL CARGO') => 'SENTRAL CARGO',
                str_contains($upperNama, 'WHIDI UTAMA') => 'WHIDI UTAMA',
                default => $nama,
            };

            return [$canonical, null];
        }

        // 3. Nama ekspedisi tanpa awalan EXPEDISI
        $knownExpeditions = [
            'PO BERSAUDARA' => 'PO BERSAUDARA',
            'J&T' => 'J&T Express',
            'J&T EXPRESS' => 'J&T Express',
            'JNT' => 'J&T Express',
            'JNE' => 'JNE Express',
            'SICEPAT' => 'SiCepat Ekspres',
            '21 EXPRESS' => '21 EXPRES',
            '21 EXPRES' => '21 EXPRES',
            'KARYA MANDIRI' => 'KARYA MANDIRI',
            'SENTRAL CARGO' => 'SENTRAL CARGO',
            'WHIDI UTAMA' => 'WHIDI UTAMA',
            'ID EXPRESS' => 'ID EXPRES',
            'ANTERAJA' => 'Anteraja',
            'WAHANA' => 'Wahana',
            'INDAH CARGO' => 'Indah Cargo',
            'DAKOTA' => 'Dakota Cargo',
            'LION PARCEL' => 'Lion Parcel',
            'LIONEL' => 'LIONEL EXPRESS',
        ];

        if (isset($knownExpeditions[$upper])) {
            return [$knownExpeditions[$upper], null];
        }

        // 4. Deteksi ekspedisi dari pola prefix nomor resi / AWB
        if (str_starts_with($upper, 'SPXID') || str_starts_with($upper, 'SPX')) {
            return ['Shopee Xpress', $raw];
        }
        if (
            str_starts_with($upper, 'JX') ||
            str_starts_with($upper, 'JP') ||
            str_starts_with($upper, 'JY') ||
            str_starts_with($upper, 'IDB') ||
            str_starts_with($upper, 'ID26') ||
            str_starts_with($upper, 'CM')
        ) {
            return ['J&T Express', $raw];
        }
        if (str_starts_with($upper, 'BLIG') || str_starts_with($upper, 'BLIGO')) {
            return ['Blibli Express', $raw];
        }
        if (str_starts_with($upper, 'GK-')) {
            return ['Gojek', $raw];
        }

        // 5. Default: Nilai adalah nomor resi / AWB
        return [null, $raw];
    }
}

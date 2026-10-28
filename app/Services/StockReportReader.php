<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * Laporan ERP "[XRSTOK02] Laporan Stock Opname Worksheet": satu baris saldo per gudang × barang (kolom No terisi),
 * diikuti baris IMEI tanpa nomor. Judul kolom diulang di tengah file (per halaman cetak) dan dilewati.
 */
class StockReportReader
{
    /**
     * Judul kolom di laporan => field.
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        'kode' => 'warehouse',
        'nama gudang' => 'warehouse_name',
        'kode item' => 'sku',
        'nama barang' => 'name',
        'family' => 'brand',
        'sort code' => 'sort_code',
        'saldo akhir' => 'qty',
    ];

    public function __construct(private XlsxReader $reader) {}

    /**
     * Saldo per gudang × barang dan tanggal posisinya; null kalau file bukan laporan stok ERP.
     *
     * @return array{stock_date: ?string, rows: list<array{warehouse: string, warehouse_name: string, sku: string, name: string, brand: string, sort_code: string, qty: int}>}|null
     */
    public function read(string $path): ?array
    {
        $strings = $this->reader->sharedStrings($path);
        $sheets = $this->reader->sheets($path);
        $sheet = reset($sheets);

        if ($sheet === false) {
            return null;
        }

        $map = null;
        $stockDate = null;
        $rows = [];

        foreach ($this->reader->iterateRows($path, $sheet, $strings) as $cells) {
            $rowNumber = (int) ($cells['_row'] ?? 0);
            unset($cells['_row']);

            if ($map === null) {
                $title = trim((string) ($cells['A'] ?? ''));

                // "Per Tanggal: 27/10/2026"
                if (preg_match('/Per\s+Tanggal\s*:\s*(\d{2}\/\d{2}\/\d{4})/i', $title, $match) === 1) {
                    $date = DateTimeImmutable::createFromFormat('!d/m/Y', $match[1]);
                    $stockDate = $date !== false ? $date->format('Y-m-d') : null;
                }

                $map = $this->headerMap($cells);

                if ($map === null && $rowNumber >= 20) {
                    return null;
                }

                continue;
            }

            // Baris IMEI (tanpa nomor) dan judul kolom yang terulang
            if (! is_numeric(trim((string) ($cells[$map['no']] ?? '')))) {
                continue;
            }

            $value = fn (string $field): string => trim((string) ($cells[$map[$field]] ?? ''));

            if ($value('warehouse') === '' || $value('sku') === '') {
                continue;
            }

            $rows[] = [
                'warehouse' => strtoupper($value('warehouse')),
                'warehouse_name' => $value('warehouse_name'),
                'sku' => $value('sku'),
                'name' => $value('name'),
                'brand' => strtoupper($value('brand')),
                'sort_code' => strtoupper($value('sort_code')),
                'qty' => (int) round((float) $value('qty')),
            ];
        }

        return $map === null ? null : ['stock_date' => $stockDate, 'rows' => $rows];
    }

    /**
     * @param  array<string, string>  $cells
     * @return array<string, string>|null
     */
    private function headerMap(array $cells): ?array
    {
        $map = [];

        foreach ($cells as $column => $label) {
            $label = strtolower(trim((string) $label));

            if ($label === 'no' && ! isset($map['no'])) {
                $map['no'] = $column;
            }

            $field = self::HEADERS[$label] ?? null;

            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $column;
            }
        }

        foreach (['no', 'warehouse', 'sku', 'qty'] as $required) {
            if (! isset($map[$required])) {
                return null;
            }
        }

        return $map + ['warehouse_name' => '', 'name' => '', 'brand' => '', 'sort_code' => ''];
    }
}

<?php

namespace App\Services;

use App\Models\CsaShipment;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Impor file spreadsheet "Update Pengiriman" (.xlsx, satu tab per cabang, kolom A–Y) ke Data Pengiriman.
 * Nama tab menjadi cabang; isi sel disalin apa adanya seperti di spreadsheet.
 */
class ShipmentSpreadsheetImporter
{
    private const CHUNK = 500;

    /** Baris judul/keterangan yang masih ditoleransi di atas header tabel sebelum tab dianggap bukan tabel pengiriman. */
    private const MAX_ROWS_BEFORE_HEADER = 10;

    /**
     * Kolom spreadsheet => kolom Data Pengiriman. V–X (lead time) tidak diambil karena dihitung ulang oleh web.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'A' => 'tanggal_order', 'B' => 'tanggal_kirim', 'C' => 'badan_usaha', 'D' => 'nama_gudang',
        'E' => 'tujuan_dealer', 'F' => 'alamat_kirim', 'G' => 'nama_kota', 'H' => 'brand', 'I' => 'no_sj',
        'J' => 'total_nominal_sj', 'K' => 'reff_note', 'L' => 'qty_unit', 'M' => 'qty_koli', 'N' => 'berat',
        'O' => 'ketentuan_biaya_kirim', 'P' => 'nama_ekspedisi', 'Q' => 'no_resi_awb', 'R' => 'biaya_kirim',
        'S' => 'status_pembayaran', 'T' => 'status_pengiriman', 'U' => 'tanggal_diterima', 'Y' => 'ket_isi_unit',
    ];

    private const DATE_FIELDS = ['tanggal_order', 'tanggal_kirim', 'tanggal_diterima'];

    private const NUMBER_FIELDS = ['total_nominal_sj', 'biaya_kirim', 'qty_unit', 'qty_koli', 'berat'];

    /** Kolom TEXT di database; kolom teks lain varchar(255). */
    private const LONG_TEXT_FIELDS = ['alamat_kirim', 'ket_isi_unit'];

    /** Nama bulan pada tanggal yang diketik sebagai teks ("04 DECEMBER 2025", "30 MARC"); cukup 3 huruf awal. */
    private const MONTHS = [
        'JAN' => 1, 'FEB' => 2, 'PEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'MEI' => 5, 'JUN' => 6, 'JUL' => 7,
        'AUG' => 8, 'AGU' => 8, 'AGS' => 8, 'SEP' => 9, 'OCT' => 10, 'OKT' => 10, 'NOV' => 11, 'NOP' => 11,
        'DEC' => 12, 'DES' => 12,
    ];

    /** Tahun tanggal terakhir yang terbaca di tab yang sedang diproses, untuk tanggal teks tanpa tahun. */
    private ?int $lastYear = null;

    /** Isian teks di kolom angka/tanggal (mis. "BELUM TAU" di TANGGAL DITERIMA) yang terpaksa dikosongkan. */
    private int $unreadable = 0;

    public function __construct(private XlsxReader $reader) {}

    /**
     * Semua tab diproses dalam satu transaksi: kalau gagal di tengah jalan, tidak ada data yang berubah.
     * Hasilnya ringkasan per tab tabel pengiriman, tab lain yang dilewati, dan jumlah isian yang dikosongkan.
     *
     * @param  bool  $replaceExisting  hapus dulu baris hasil import spreadsheet sebelumnya untuk tab yang ada di file
     * @return array{tabs: array<string, array{imported: int, replaced: int}>, skipped: list<string>, unreadable: int}
     */
    public function import(string $path, bool $replaceExisting = true): array
    {
        $sharedStrings = $this->reader->sharedStrings($path);
        $sheets = $this->reader->sheets($path);
        $this->unreadable = 0;

        return DB::transaction(function () use ($path, $sheets, $sharedStrings, $replaceExisting): array {
            $tabs = [];
            $skipped = [];

            foreach ($sheets as $sheetName => $sheetPath) {
                $tab = $this->cabang($sheetName);
                // Dua tab dengan nama sama (beda spasi/huruf besar) digabung, jangan saling menghapus
                $replace = $replaceExisting && ! isset($tabs[$tab]);

                $result = $this->importSheet($path, $sheetPath, $sharedStrings, $tab, $replace);

                if ($result === null) {
                    $skipped[] = $tab;

                    continue;
                }

                $tabs[$tab] = [
                    'imported' => ($tabs[$tab]['imported'] ?? 0) + $result['imported'],
                    'replaced' => ($tabs[$tab]['replaced'] ?? 0) + $result['replaced'],
                ];
            }

            return ['tabs' => $tabs, 'skipped' => $skipped, 'unreadable' => $this->unreadable];
        });
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array{imported: int, replaced: int}|null null kalau tab ini bukan tabel pengiriman
     */
    private function importSheet(string $path, string $sheetPath, array $sharedStrings, string $tab, bool $replace): ?array
    {
        $this->lastYear = null;
        $headerFound = false;
        $rowsBeforeHeader = 0;
        $imported = 0;
        $replaced = 0;
        $batch = [];

        foreach ($this->reader->iterateRows($path, $sheetPath, $sharedStrings) as $cells) {
            unset($cells['_row']);

            if (! $headerFound) {
                if ($cells === []) {
                    continue;
                }

                if (! $this->isHeader($cells)) {
                    if (++$rowsBeforeHeader >= self::MAX_ROWS_BEFORE_HEADER) {
                        return null;
                    }

                    continue;
                }

                $headerFound = true;

                if ($replace) {
                    $replaced = CsaShipment::query()
                        ->where('source', CsaShipment::SOURCE_SPREADSHEET)
                        ->where('target_sheet', $tab)
                        ->delete();
                }

                continue;
            }

            $record = $this->mapRow($cells, $tab);

            if ($record === null) {
                continue;
            }

            $batch[] = $record;

            if (count($batch) === self::CHUNK) {
                DB::table('csa_shipments')->insert($batch);
                $imported += count($batch);
                $batch = [];
            }
        }

        if (! $headerFound) {
            return null;
        }

        if ($batch !== []) {
            DB::table('csa_shipments')->insert($batch);
            $imported += count($batch);
        }

        return ['imported' => $imported, 'replaced' => $replaced];
    }

    /**
     * @param  array<string, string>  $cells  nilai mentah per huruf kolom dari XlsxReader
     * @return array<string, mixed>|null null untuk baris template/keterangan tanpa nomor SJ dan tujuan
     */
    public function mapRow(array $cells, string $tab): ?array
    {
        $values = [];

        foreach (self::COLUMNS as $column => $field) {
            $values[$field] = $this->clean($cells[$column] ?? '');
        }

        if (in_array($values['no_sj'], ['', '-'], true) && in_array($values['tujuan_dealer'], ['', '-'], true)) {
            return null;
        }

        $record = [];

        foreach ($values as $field => $value) {
            $record[$field] = match (true) {
                in_array($field, self::DATE_FIELDS, true) => $this->date($value),
                $field === 'total_nominal_sj', $field === 'biaya_kirim' => $this->money($value),
                $field === 'qty_unit', $field === 'qty_koli' => $this->integer($value),
                $field === 'berat' => $this->decimal($value),
                default => $this->text($value, in_array($field, self::LONG_TEXT_FIELDS, true) ? null : 255),
            };
        }

        // QTY UNIT kadang diisi jenis barang ("DOKUMEN", "ATK"): simpan sebagai KET. ISI UNIT kalau masih kosong
        if ($record['qty_unit'] === null && $record['ket_isi_unit'] === null && $this->isText($values['qty_unit'])) {
            $record['ket_isi_unit'] = $values['qty_unit'];
            $values['qty_unit'] = '';
        }

        foreach ([...self::DATE_FIELDS, ...self::NUMBER_FIELDS] as $field) {
            if ($record[$field] === null && $this->isText($values[$field])) {
                $this->unreadable++;
            }
        }

        $now = now();

        return [
            ...$record,
            // Kolom wajib di database: kosong di spreadsheet berarti 0 / belum diisi
            'no_sj' => $record['no_sj'] ?? '',
            'total_nominal_sj' => $record['total_nominal_sj'] ?? 0,
            'qty_unit' => $record['qty_unit'] ?? 0,
            'qty_koli' => $record['qty_koli'] ?? 0,
            'berat' => $record['berat'] ?? 0,
            'csa_import_id' => null,
            'source' => CsaShipment::SOURCE_SPREADSHEET,
            'kode_gudang' => 'SPREADSHEET',
            'target_sheet' => $tab,
            // Baris ini memang berasal dari Google Sheet, jadi tidak perlu dikirim ke sana lagi
            'is_synced' => true,
            'already_in_sheet' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Nama tab menjadi cabang; disamakan dengan penulisan tab di config kalau cocok (mis. "JAKARTA PC " → "JAKARTA PC").
     */
    private function cabang(string $sheetName): string
    {
        $name = $this->clean($sheetName);

        foreach ((array) config('data_pengiriman.tabs', []) as $tab) {
            if (mb_strtoupper(trim($tab)) === mb_strtoupper($name)) {
                return trim($tab);
            }
        }

        return mb_substr($name, 0, 255);
    }

    /**
     * Header tabel pengiriman: kolom E "TUJUAN/DEALER" dan kolom I "NOMOR SJ".
     *
     * @param  array<string, string>  $cells
     */
    private function isHeader(array $cells): bool
    {
        $label = fn (string $column): string => mb_strtoupper($this->clean($cells[$column] ?? ''));

        return str_contains($label('I'), 'SJ')
            && (str_contains($label('E'), 'TUJUAN') || str_contains($label('E'), 'DEALER'));
    }

    private function clean(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value) ?? trim($value);
    }

    /**
     * Isian berupa keterangan, bukan kosong, "-", error rumus (#REF!, #N/A) atau angka 0.
     */
    private function isText(string $value): bool
    {
        return $value !== '' && $value !== '-' && ! str_starts_with($value, '#')
            && ! (is_numeric($value) && (float) $value === 0.0);
    }

    /**
     * Teks apa adanya. Angka dari sel numerik ditulis utuh (1.349678322E9 → 1349678322, 3.0 → 3).
     */
    private function text(string $value, ?int $maxLength): ?string
    {
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $value = $this->reader->normalizeIdentifier($value);
        }

        return $maxLength === null ? $value : mb_substr($value, 0, $maxLength);
    }

    private function date(string $value): ?string
    {
        if ($value === '' || $value === '-') {
            return null;
        }

        $date = $this->parseDate($value);

        if ($date === null || $date->format('Y') < 2000 || $date->format('Y') >= 2100) {
            return null;
        }

        $this->lastYear = (int) $date->format('Y');

        return $date->format('Y-m-d');
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        // Sel tanggal: nomor seri Excel (46024 = 2 Jan 2026)
        if (is_numeric($value)) {
            $serial = (int) floor((float) $value);

            return $serial > 0 && $serial < 2958466
                ? (new DateTimeImmutable('1899-12-30'))->modify("+{$serial} days")
                : null;
        }

        // Teks d/m/Y; teks m/d/Y muncul kalau tanggal diketik format Amerika (05/30/2026)
        foreach (['d/m/Y', 'm/d/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || $errors['warning_count'] + $errors['error_count'] === 0)) {
                return $date;
            }
        }

        // "04 DECEMBER 2025", atau "30 MARC" tanpa tahun: tahunnya mengikuti baris sebelumnya di tab yang sama
        if (preg_match('/^(\d{1,2})[\s-]+([A-Za-z]{3,})\.?(?:[\s-]+(\d{4}))?$/', $value, $match)) {
            $month = self::MONTHS[strtoupper(substr($match[2], 0, 3))] ?? null;
            $year = isset($match[3]) ? (int) $match[3] : $this->lastYear;

            if ($month !== null && $year !== null && checkdate($month, (int) $match[1], $year)) {
                return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, (int) $match[1]));
            }
        }

        return null;
    }

    /**
     * Angka atau teks rupiah ("Rp15.880.000", "9.000,00", "36.000 + 5.000" → angka pertama).
     */
    private function money(string $value): ?float
    {
        if (is_numeric($value)) {
            $number = (float) $value;
        } elseif (preg_match('/-?\d[\d.,]*/', $value, $match)) {
            $number = (float) str_replace(['.', ','], '', (string) preg_replace('/[.,]\d{1,2}$/', '', $match[0]));
        } else {
            return null;
        }

        // decimal(15,2)
        return abs($number) < 1e13 ? round($number, 2) : null;
    }

    /**
     * Bilangan bulat pertama, misal "26 + 1 ACC" → 26.
     */
    private function integer(string $value): ?int
    {
        if (is_numeric($value)) {
            $number = round((float) $value);
        } elseif (preg_match('/-?\d+/', $value, $match)) {
            $number = (float) $match[0];
        } else {
            return null;
        }

        return abs($number) <= 2147483647 ? (int) $number : null;
    }

    /**
     * Desimal pertama, misal "3,20 KG" → 3.2.
     */
    private function decimal(string $value): ?float
    {
        if (is_numeric($value)) {
            $number = (float) $value;
        } elseif (preg_match('/-?\d+(?:[.,]\d+)?/', $value, $match)) {
            $number = (float) str_replace(',', '.', $match[0]);
        } else {
            return null;
        }

        // decimal(8,2)
        return abs($number) < 1000000 ? round($number, 2) : null;
    }
}

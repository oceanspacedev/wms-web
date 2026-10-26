<?php

namespace App\Services;

use App\Models\CsaShipment;
use App\Models\WarehouseMapping;
use Exception;

/**
 * Laporan ERP "[XRSTOK23] Laporan Realisasi Transfer Antar Gudang".
 *
 * Seperti di spreadsheet lama, yang masuk Data Pengiriman hanya TO dari gudang pusat (GMJKT) ke depo GM:
 * satu baris per nomor ISSUE (mis. 2610000224-01) di cabang JAKARTA PIK dengan tujuan "DEPO <KOTA>".
 * Baris RECEIVE (-02) adalah penerimaan di depo, sedangkan baris tanpa nomor transaksi hanya rincian IMEI.
 */
class TransferOrderReportParser
{
    public const ORIGIN_WAREHOUSE = 'GMJKT';

    public const TARGET_SHEET = 'JAKARTA PIK';

    public const BADAN_USAHA = 'PT. MEDIA SELULAR INDONESIA';

    public function __construct(private XlsxReader $reader) {}

    /**
     * @param  array<string, string>  $cells
     */
    public function isTitleRow(array $cells): bool
    {
        $text = strtolower(implode(' ', $cells));

        return str_contains($text, 'xrstok23') || str_contains($text, 'realisasi transfer');
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
    public function parse(string $filePath, string $sheetPath, array $sharedStrings): array
    {
        $depots = WarehouseMapping::query()
            ->where('is_active', true)
            ->where('csa_code', 'like', 'GM%')
            ->get(['csa_code', 'csa_name', 'target_sheet'])
            ->keyBy('csa_code');

        $originName = $depots->get(self::ORIGIN_WAREHOUSE)?->csa_name ?? self::ORIGIN_WAREHOUSE;
        $addresses = $this->depotAddresses();
        $map = [];
        $rawRowCount = 0;
        $grouped = [];

        foreach ($this->reader->iterateRows($filePath, $sheetPath, $sharedStrings) as $cells) {
            if ($map === []) {
                if ($this->isHeader($cells)) {
                    $map = $this->headerMap($cells);
                }

                continue;
            }

            $noTo = $this->reader->normalizeIdentifier($this->cell($cells, $map, 'transaksi'));

            if ($noTo === '') {
                continue;
            }

            $rawRowCount++;

            $dari = $this->warehouseCode($this->cell($cells, $map, 'dari'));
            $ke = $this->warehouseCode($this->cell($cells, $map, 'ke'));

            if (strtoupper($this->cell($cells, $map, 'jenis')) !== 'ISSUE'
                || $dari !== self::ORIGIN_WAREHOUSE
                || $ke === self::ORIGIN_WAREHOUSE
                || ! $depots->has($ke)) {
                continue;
            }

            if (! isset($grouped[$noTo])) {
                $kota = (string) $depots[$ke]->target_sheet;
                $tujuan = 'DEPO '.$kota;
                $tanggalKirim = $this->excelDate($this->cell($cells, $map, 'tgl_kirim'));

                $grouped[$noTo] = [
                    'no_sj' => $noTo,
                    'no_trans' => explode('-', $noTo)[0],
                    'no_so' => null,
                    'tanggal_order' => $tanggalKirim,
                    'tanggal_kirim' => $tanggalKirim,
                    'tanggal_diterima' => $this->excelDate($this->cell($cells, $map, 'tgl_terima')),
                    'badan_usaha' => self::BADAN_USAHA,
                    'kode_gudang' => $dari,
                    'nama_gudang' => $originName,
                    'target_sheet' => self::TARGET_SHEET,
                    'tujuan_dealer' => $tujuan,
                    'alamat_kirim' => $addresses[$tujuan] ?? null,
                    'nama_kota' => $kota,
                    'brand' => null,
                    'reff_note' => $this->cell($cells, $map, 'ref_note') ?: null,
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
                    'brand_values' => [],
                ];
            }

            $nominal = (float) ($this->cell($cells, $map, 'jumlah') ?: 0);
            $grouped[$noTo]['qty_unit'] += (int) ($this->cell($cells, $map, 'qty') ?: 0);
            $grouped[$noTo]['total_nominal_sj'] += $nominal;

            // Brand = kata pertama nama barang bernilai terbesar ("TECNO - HP 4G - ..." / "HP SAMSUNG ..." → TECNO / SAMSUNG);
            // hadiah bernilai 0 tidak dihitung
            $words = preg_split('/[\s-]+/', strtoupper($this->cell($cells, $map, 'nama_item')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $brand = ($words[0] ?? '') === 'HP' ? ($words[1] ?? '') : ($words[0] ?? '');
            if ($brand !== '' && $nominal > 0) {
                $grouped[$noTo]['brand_values'][$brand] = ($grouped[$noTo]['brand_values'][$brand] ?? 0) + $nominal;
            }
        }

        if ($map === []) {
            throw new Exception('Header laporan realisasi TO tidak ditemukan');
        }

        return $this->finalize($grouped, $rawRowCount);
    }

    /**
     * @param  array<string, array<string, mixed>>  $grouped
     * @return array{
     *     total_raw_rows: int,
     *     total_shipments: int,
     *     by_sheet: array<string, array{total_sj: int, total_qty: int, total_nominal: float}>,
     *     shipments: array<int, array<string, mixed>>
     * }
     */
    protected function finalize(array $grouped, int $rawRowCount): array
    {
        $shipments = [];
        $byDepot = [];
        $brandsByRefPrefix = [];

        foreach ($grouped as $noTo => $shipment) {
            arsort($shipment['brand_values']);
            $brand = array_key_first($shipment['brand_values']);
            $grouped[$noTo]['brand'] = $brand;

            if ($brand !== null) {
                $prefix = $this->refPrefix($shipment['reff_note']);
                $brandsByRefPrefix[$prefix][$brand] = ($brandsByRefPrefix[$prefix][$brand] ?? 0) + 1;
            }
        }

        foreach ($grouped as $shipment) {
            // TO yang isinya hanya hadiah: brand mengikuti TO lain dengan awalan Ref.Note yang sama (T-PADANG → TECNO)
            if ($shipment['brand'] === null) {
                $candidates = $brandsByRefPrefix[$this->refPrefix($shipment['reff_note'])] ?? [];
                arsort($candidates);
                $shipment['brand'] = array_key_first($candidates);
            }

            unset($shipment['brand_values']);

            $depot = $shipment['tujuan_dealer'];
            $byDepot[$depot] ??= ['total_sj' => 0, 'total_qty' => 0, 'total_nominal' => 0.0];
            $byDepot[$depot]['total_sj']++;
            $byDepot[$depot]['total_qty'] += $shipment['qty_unit'];
            $byDepot[$depot]['total_nominal'] += $shipment['total_nominal_sj'];

            $shipments[] = $shipment;
        }

        ksort($byDepot);

        return [
            'total_raw_rows' => $rawRowCount,
            'total_shipments' => count($shipments),
            'by_sheet' => $byDepot,
            'shipments' => $shipments,
        ];
    }

    /**
     * Alamat depo diambil dari baris TO terakhir di cabang JAKARTA PIK yang alamatnya sudah diisi.
     *
     * @return array<string, string>
     */
    protected function depotAddresses(): array
    {
        $latestIds = CsaShipment::query()
            ->selectRaw('max(id)')
            ->where('target_sheet', self::TARGET_SHEET)
            ->where('tujuan_dealer', 'like', 'DEPO %')
            ->whereNotNull('alamat_kirim')
            ->where('alamat_kirim', '!=', '')
            ->groupBy('tujuan_dealer');

        return CsaShipment::query()
            ->whereIn('id', $latestIds)
            ->pluck('alamat_kirim', 'tujuan_dealer')
            ->mapWithKeys(fn (string $alamat, string $tujuan): array => [mb_strtoupper(trim($tujuan)) => $alamat])
            ->all();
    }

    /**
     * @param  array<string, string>  $cells
     */
    protected function isHeader(array $cells): bool
    {
        $labels = array_map(fn (string $label): string => strtolower(trim($label)), array_diff_key($cells, ['_row' => true]));

        return in_array('transaksi', $labels, true) && in_array('jenis', $labels, true) && in_array('ke', $labels, true);
    }

    /**
     * @param  array<string, string>  $cells
     * @return array<string, string>
     */
    protected function headerMap(array $cells): array
    {
        $fields = [
            'transaksi' => 'transaksi',
            'tgl kirim' => 'tgl_kirim',
            'dari' => 'dari',
            'ke' => 'ke',
            'jenis' => 'jenis',
            'tgl terima' => 'tgl_terima',
            'ref.note' => 'ref_note',
            'nama item' => 'nama_item',
            'qty' => 'qty',
            'jumlah' => 'jumlah',
        ];

        $map = [];

        foreach ($cells as $column => $label) {
            $field = $fields[strtolower(trim($label))] ?? null;

            if ($column !== '_row' && $field !== null && ! isset($map[$field])) {
                $map[$field] = $column;
            }
        }

        foreach (['transaksi', 'dari', 'ke', 'jenis', 'qty', 'jumlah'] as $required) {
            if (! isset($map[$required])) {
                throw new Exception("Kolom {$required} tidak ditemukan pada laporan realisasi TO");
            }
        }

        return $map;
    }

    protected function refPrefix(?string $reffNote): string
    {
        return strtoupper(trim(explode('-', (string) $reffNote)[0]));
    }

    /**
     * Kode gudang ditulis "[GMPDG   ]" di sisi yang bukan gudang pelapor.
     */
    protected function warehouseCode(string $value): string
    {
        return strtoupper(trim(str_replace(['[', ']'], '', $value)));
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, string>  $map
     */
    protected function cell(array $cells, array $map, string $field): string
    {
        $column = $map[$field] ?? null;

        return $column === null ? '' : trim((string) ($cells[$column] ?? ''));
    }

    protected function excelDate(string $value): ?string
    {
        if ($value === '' || ! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return date('Y-m-d', (int) strtotime('1899-12-30 +'.(int) $value.' days'));
    }
}

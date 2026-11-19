<?php

namespace App\Services;

use App\Models\CsaShipment;
use App\Models\Expedition;
use App\Models\ExpeditionRateCard;
use App\Models\FreightInvoice;
use App\Models\FreightInvoiceItem;
use App\Support\Rupiah;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class FreightReconciliationService
{
    /**
     * Penulisan kota tujuan di invoice ekspedisi yang berbeda dengan Tarif Kontrak.
     *
     * @var array<string, string>
     */
    private const DESTINATION_ALIASES = [
        'JOGYAKARTA' => 'YOGYAKARTA',
        'JOGJAKARTA' => 'YOGYAKARTA',
        'JOGJA' => 'YOGYAKARTA',
        // Nama lama Makassar yang masih dipakai invoice 21 Express
        'UJUNG PANDANG' => 'MAKASSAR',
        'UJUNGPANDANG' => 'MAKASSAR',
    ];

    /**
     * Nilai placeholder pada kolom resi/SJ yang tidak boleh dipakai sebagai kunci pencocokan Data Pengiriman.
     *
     * @var list<string>
     */
    private const PLACEHOLDER_IDENTIFIERS = [
        '', '-', '0', 'MANUAL', 'DOKUMEN', 'DOKUEMN', 'BATCH 1', 'BATCH 2', 'BATCH1', 'BATCH2', 'N/A', 'NA', 'NULL', 'NAN',
    ];

    public function __construct(private XlsxReader $reader = new XlsxReader) {}

    /**
     * Cek setiap baris invoice ekspedisi terhadap Tarif Kontrak ekspedisi tersebut (yang juga dipakai menu Cek Tarif).
     * Asal pengiriman diambil dari kolom asal di invoice kalau ada, selain itu dari Kota Asal yang dipilih saat upload.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     */
    public function reconcile(FreightInvoice $invoice, array $rawItems): FreightInvoice
    {
        return DB::transaction(function () use ($invoice, $rawItems) {
            // Persetujuan manual admin tetap berlaku saat audit ulang
            $manualApprovals = $invoice->items()->whereNotNull('manual_approved_at')->get()->keyBy('no_resi_awb');

            // Delete previous items if re-auditing
            $invoice->items()->delete();

            $rateCards = $this->rateCardIndex($invoice->expedition_id);
            $origins = collect($rateCards)->flatten()->map(fn (ExpeditionRateCard $card): string => $this->upper($card->origin_depo))->unique()->values()->all();
            $expedition = $invoice->expedition;

            // Pre-load Data Pengiriman (csa_shipments) dalam batch query untuk pencocokan resi, SJ, & smart fallback
            $shipmentsByResi = $this->loadShipmentsByResi($rawItems);
            $shipmentsBySj = $this->loadShipmentsBySj($rawItems);
            $fallbackShipments = $this->loadFallbackShipments($rawItems, $invoice, $origins);

            // Pre-load previously approved resi numbers to catch duplicate invoicing
            $previousResi = FreightInvoiceItem::where('freight_invoice_id', '!=', $invoice->id)
                ->whereHas('invoice', fn ($q) => $q->whereIn('status', ['approved', 'paid']))
                ->pluck('freight_invoice_id', 'no_resi_awb')
                ->toArray();

            $itemsToInsert = [];
            $now = now();

            foreach ($rawItems as $item) {
                $noResi = $this->reader->normalizeIdentifier((string) ($item['no_resi_awb'] ?? ''));
                $noSj = $this->reader->normalizeIdentifier((string) ($item['no_sj'] ?? ''));
                $billedWeight = (float) ($item['billed_weight'] ?? 1.0);
                $grossWeight = (float) ($item['gross_weight'] ?? 0.0);
                $billedRate = (float) ($item['billed_rate'] ?? 0.0);
                $billedInsurance = (float) ($item['billed_insurance'] ?? 0.0);
                $billedTotal = (float) ($item['billed_total'] ?? 0.0);
                $billedService = strtoupper(trim((string) ($item['service_type'] ?? '')));
                $service = $this->normalizeService($billedService);

                if ($billedTotal <= 0 && $billedRate > 0) {
                    $billedTotal = ($billedWeight * $billedRate) + $billedInsurance;
                } elseif ($billedRate <= 0 && $billedTotal > 0 && $billedWeight > 0) {
                    $billedRate = round(($billedTotal - $billedInsurance) / $billedWeight, 2);
                }

                $auditStatus = 'matched';
                $auditNotes = [];
                // Berat timbang ekspedisi (gross) di invoice
                $actualWeight = $grossWeight > 0 ? $grossWeight : null;
                $expectedTotal = $billedTotal;
                $agreedRate = 0.0;
                $matchedMinKg = 1.0;
                $originDepo = $this->resolveOrigin([$item['origin_depo'] ?? '', $invoice->origin_city], $origins);

                // 1. DUPLICATE CHECK & CEK TARIF KONTRAK
                if (! empty($noResi) && isset($previousResi[$noResi])) {
                    $auditStatus = 'duplicate';
                    $auditNotes[] = "Resi sudah pernah ditagihkan pada Faktur #{$previousResi[$noResi]}";
                    $expectedTotal = 0.0;
                } else {
                    $destinations = $this->destinationKeys([$item['destination_city'] ?? '']);
                    $destinationLabel = $destinations[0] ?? '-';
                    $rateCard = $this->findRateCard($rateCards, $originDepo, $destinations, $service);

                    if ($rateCard) {
                        $agreedRate = (float) $rateCard->rate_per_kg;
                        $matchedMinKg = max(1.0, (float) $rateCard->min_kg);
                        $hasRateIssue = $billedRate > 0 && $billedRate > $agreedRate + 0.5;
                        $hasWeightIssue = false;
                        $chargeWeight = $billedWeight;

                        if ($grossWeight > 0) {
                            // Berat ditagih = berat timbang dibulatkan ke atas, minimal min. kg di Tarif Kontrak
                            $expectedWeight = max(ceil(round($grossWeight, 2)), (float) $rateCard->min_kg);

                            if ($billedWeight > $expectedWeight + 0.5) {
                                $hasWeightIssue = true;
                                $chargeWeight = $expectedWeight;
                                $auditNotes[] = "Berat Lebih: Ditagih {$this->kg($billedWeight)} kg vs seharusnya {$this->kg($expectedWeight)} kg (timbang {$this->kg($grossWeight)} kg, min. {$this->kg((float) $rateCard->min_kg)} kg)";
                            }
                        }

                        if ($hasRateIssue) {
                            $auditNotes[] = 'Tarif Melebihi Kontrak: Ditagih Rp '.$this->rupiah($billedRate).'/kg vs Tarif Kontrak Rp '.$this->rupiah($agreedRate).'/kg (+Rp '.$this->rupiah($billedRate - $agreedRate).'/kg)';
                        } elseif ($billedRate > 0 && $billedRate < $agreedRate - 0.5) {
                            $auditNotes[] = 'Lebih murah dari Tarif Kontrak (Rp '.$this->rupiah($agreedRate).'/kg)';
                        }

                        $auditStatus = match (true) {
                            $hasWeightIssue && $hasRateIssue => 'discrepancy_both',
                            $hasWeightIssue => 'discrepancy_weight',
                            $hasRateIssue => 'discrepancy_rate',
                            default => 'matched',
                        };

                        // Sesuai berarti dibayar penuh; yang bermasalah dihitung ulang dengan Tarif Kontrak
                        if ($auditStatus !== 'matched') {
                            $contractTotal = round($chargeWeight * ($billedRate > 0 ? min($billedRate, $agreedRate) : $agreedRate) + $billedInsurance, 2);
                            $expectedTotal = min($billedTotal, $contractTotal);
                        }
                    } elseif ($service !== null && $service !== 'DARAT'
                        && ($darat = $this->findRateCard($rateCards, $originDepo, $destinations, 'DARAT'))) {
                        // Layanan yang ditagih (mis. ECO/UDARA) belum ada tarifnya: bandingkan dengan tarif DARAT rute yang sama
                        $agreedRate = (float) $darat->rate_per_kg;
                        $matchedMinKg = max(1.0, (float) $darat->min_kg);
                        $daratWeight = max($grossWeight > 0 ? ceil(round($grossWeight, 2)) : $billedWeight, (float) $darat->min_kg);
                        $daratCost = round($daratWeight * $agreedRate, 2);

                        if ($billedTotal - $billedInsurance <= $daratCost + 0.5) {
                            $auditStatus = 'matched';
                            $auditNotes[] = "Tarif {$billedService} {$originDepo} → {$destinationLabel} belum ada di Tarif Kontrak; tagihan tidak lebih mahal dari DARAT ({$this->kg($daratWeight)} kg × Rp {$this->rupiah($agreedRate)} = Rp {$this->rupiah($daratCost)})";
                        } else {
                            $auditStatus = 'revisi_darat';
                            $auditNotes[] = "Tarif {$billedService} {$originDepo} → {$destinationLabel} belum ada di Tarif Kontrak; lebih murah pakai DARAT: {$this->kg($daratWeight)} kg × Rp {$this->rupiah($agreedRate)} = Rp {$this->rupiah($daratCost)}";
                            $expectedTotal = min($billedTotal, $daratCost + $billedInsurance);
                        }
                    } else {
                        // Belum bisa dinilai (bukan koreksi): dihitung terpisah sebagai "belum terverifikasi"
                        $auditStatus = 'missing_rate';
                        $route = ($originDepo ?? '(kota asal belum dipilih)').' → '.$destinationLabel.($service ? " ({$service})" : '');
                        $auditNotes[] = "Tarif Kontrak untuk rute {$route} belum terdaftar";
                        $expectedTotal = 0.0;
                    }
                }

                // 2. COCOKKAN KE DATA PENGIRIMAN (Update Pengiriman All Depo / csa_shipments)
                $resolvedOrigin = $originDepo ?? (($item['origin_depo'] ?? '') ?: ($invoice->origin_city ?: null));
                $shipmentMatch = $this->matchAndSummarizeShipment(
                    noResi: $noResi,
                    noSj: $noSj,
                    originDepo: $resolvedOrigin,
                    item: $item,
                    expedition: $expedition,
                    shipmentsByResi: $shipmentsByResi,
                    shipmentsBySj: $shipmentsBySj,
                    fallbackShipments: $fallbackShipments,
                );

                $shipmentMatchStatus = 'unmatched';
                $shipmentMatchedBy = $shipmentMatch['matched_by'];
                $shipmentWeight = $shipmentMatch['weight'];

                if ($shipmentMatch['csa_shipment_id'] !== null) {
                    $hasExpeditionIssue = false;
                    $warehouseExp = trim((string) ($shipmentMatch['nama_ekspedisi'] ?? ''));

                    if ($expedition !== null && $warehouseExp !== '' && ! $this->expeditionMatches($expedition, $warehouseExp)) {
                        $hasExpeditionIssue = true;
                    }

                    if ($hasExpeditionIssue) {
                        $shipmentMatchStatus = 'expedition_mismatch';
                        $auditNotes[] = "Beda Ekspedisi: tagihan {$expedition->name} vs data gudang {$warehouseExp}";
                    } elseif ($shipmentWeight !== null && $shipmentWeight > 0) {
                        $expectedWarehouseWeight = max(ceil(round($shipmentWeight, 2)), $matchedMinKg);

                        if ($billedWeight > $expectedWarehouseWeight + 0.5) {
                            $shipmentMatchStatus = 'weight_mismatch';
                            $auditNotes[] = "Berat vs Data Pengiriman: ditagih {$this->kg($billedWeight)} kg vs gudang {$this->kg($shipmentWeight)} kg";
                        } else {
                            $shipmentMatchStatus = 'matched';
                        }
                    } else {
                        $shipmentMatchStatus = 'matched';
                    }

                    if ($shipmentMatchedBy === 'dealer_date' && filled($shipmentMatch['tujuan_dealer'])) {
                        $auditNotes[] = "Cocok via Toko [{$shipmentMatch['tujuan_dealer']}]";
                    }
                }

                $finalNoSj = $shipmentMatch['no_sj'] ?: ($noSj ?: null);
                $finalBadanUsaha = $shipmentMatch['badan_usaha'] ?: (($item['invoice_badan_usaha'] ?? '') ?: null);
                $finalReffNote = $shipmentMatch['reff_note'] ?: (($item['invoice_reff_note'] ?? '') ?: null);
                $finalBrand = $shipmentMatch['brand'] ?: (($item['invoice_brand'] ?? '') ?: null);
                $finalNominalSj = $shipmentMatch['nominal_sj'] > 0
                    ? $shipmentMatch['nominal_sj']
                    : (float) ($item['invoice_nominal_sj'] ?? 0.0);

                $discrepancyAmount = $auditStatus === 'missing_rate' ? 0.0 : round(max(0, $billedTotal - $expectedTotal), 2);
                $resiKey = $noResi ?: ($noSj ?: '-');
                $manual = $manualApprovals->get($resiKey);

                $itemsToInsert[] = [
                    'freight_invoice_id' => $invoice->id,
                    'csa_shipment_id' => $shipmentMatch['csa_shipment_id'],
                    'no_resi_awb' => $resiKey,
                    'no_sj' => $finalNoSj,
                    'shipment_badan_usaha' => $finalBadanUsaha,
                    'shipment_reff_note' => $finalReffNote,
                    'shipment_brand' => $finalBrand,
                    'shipment_nominal_sj' => $finalNominalSj,
                    'origin_depo' => $originDepo ?? (($item['origin_depo'] ?? '') ?: null),
                    'destination_city' => ($item['destination_city'] ?? '') ?: null,
                    'service_type' => $billedService ?: null,
                    'billed_weight' => $billedWeight,
                    'actual_weight' => $actualWeight,
                    'shipment_weight' => $shipmentWeight,
                    'weight_discrepancy' => $actualWeight !== null ? round($billedWeight - $actualWeight, 2) : 0.0,
                    'billed_rate' => $billedRate,
                    'agreed_rate' => $agreedRate,
                    'billed_insurance' => $billedInsurance,
                    'agreed_insurance' => 0.0,
                    'billed_total' => $billedTotal,
                    'expected_total' => $expectedTotal,
                    'discrepancy_amount' => $discrepancyAmount,
                    'audit_status' => $auditStatus,
                    'shipment_match_status' => $shipmentMatchStatus,
                    'shipment_matched_by' => $shipmentMatchedBy,
                    'audit_notes' => implode(' | ', $auditNotes),
                    'manual_approved_amount' => $manual?->manual_approved_amount,
                    'manual_note' => $manual?->manual_note,
                    'manual_approved_by' => $manual?->manual_approved_by,
                    'manual_approved_at' => $manual?->manual_approved_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Batch insert items in chunks of 500
            foreach (array_chunk($itemsToInsert, 500) as $chunk) {
                DB::table('freight_invoice_items')->insert($chunk);
            }

            $this->refreshTotals($invoice, 'audited');

            return $invoice->fresh(['items']);
        });
    }

    /**
     * Hitung ulang ringkasan invoice dari baris-barisnya (dipakai setelah audit maupun setelah persetujuan manual).
     * Tagihan yang tarif rutenya belum ada di Tarif Kontrak dipisah sebagai "belum terverifikasi", bukan koreksi.
     */
    public function refreshTotals(FreightInvoice $invoice, ?string $status = null): FreightInvoice
    {
        $items = $invoice->items()->get();

        $invoice->update(array_filter([
            'total_billed_amount' => round($items->sum(fn (FreightInvoiceItem $item): float => (float) $item->billed_total), 2),
            'total_approved_amount' => round($items->sum(fn (FreightInvoiceItem $item): float => $item->approvedAmount()), 2),
            'total_discrepancy_amount' => round($items->sum(fn (FreightInvoiceItem $item): float => $item->correctionAmount()), 2),
            'total_unverified_amount' => round($items->filter->isUnverified()->sum(fn (FreightInvoiceItem $item): float => (float) $item->billed_total), 2),
            'total_items_count' => $items->count(),
            'matched_count' => $items->filter(fn (FreightInvoiceItem $item): bool => $item->isManuallyApproved() || $item->audit_status === 'matched')->count(),
            'unrecognized_count' => $items->filter->isUnverified()->count(),
            'duplicate_count' => $items->filter(fn (FreightInvoiceItem $item): bool => ! $item->isManuallyApproved() && $item->audit_status === 'duplicate')->count(),
            'discrepancy_count' => $items->filter(fn (FreightInvoiceItem $item): bool => ! $item->isManuallyApproved()
                && ! $item->isUnverified() && ! in_array($item->audit_status, ['matched', 'duplicate'], true))->count(),
            'status' => $status,
        ], fn ($value): bool => $value !== null));

        return $invoice;
    }

    /**
     * Parse Excel/CSV file from expedition invoice and convert into structured array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseInvoiceFile(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'csv' || $extension === 'txt') {
            return $this->parseCsv($filePath);
        }

        if ($extension === 'xlsx') {
            return $this->parseXlsx($filePath);
        }

        throw new Exception("Format file [{$extension}] tidak didukung. Harap unggah file .xlsx atau .csv");
    }

    /**
     * Parse CSV invoice file.
     */
    protected function parseCsv(string $filePath): array
    {
        $rows = [];
        if (($handle = fopen($filePath, 'r')) !== false) {
            $header = null;
            while (($data = fgetcsv($handle, 2000, ',')) !== false) {
                if (! $header) {
                    $header = array_values($this->normalizeHeaderColumns($data));

                    continue;
                }
                $row = array_combine($header, array_pad($data, count($header), ''));
                $normalized = $this->mapNormalizedFields($row);

                if ($normalized['no_resi_awb'] !== '' || $normalized['no_sj'] !== '' || $normalized['destination_city'] !== '') {
                    $rows[] = $normalized;
                }
            }
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Parse XLSX invoice file using ZipArchive and XMLReader.
     */
    protected function parseXlsx(string $filePath): array
    {
        $sharedStrings = $this->reader->sharedStrings($filePath);
        $sheets = $this->reader->sheets($filePath);
        $invoiceSheet = null;

        foreach ($sheets as $sheetPath) {
            if ($this->sheetLooksLikeInvoice($filePath, $sheetPath, $sharedStrings)) {
                $invoiceSheet = $sheetPath;

                break;
            }
        }

        if ($invoiceSheet === null) {
            throw new Exception('Tidak menemukan sheet invoice ekspedisi pada file Excel');
        }

        $headerMap = [];
        $rows = [];

        foreach ($this->reader->iterateRows($filePath, $invoiceSheet, $sharedStrings) as $cells) {
            unset($cells['_row']);

            if ($headerMap === []) {
                $joined = strtolower(implode(' ', $cells));

                if ($this->textLooksLikeInvoiceHeader($joined)) {
                    $headerMap = $this->normalizeHeaderColumns($cells);
                }

                continue;
            }

            $mappedRow = [];

            foreach ($cells as $column => $value) {
                $field = $headerMap[$column] ?? $column;
                $mappedRow[$field] = $value;
            }

            $normalized = $this->mapNormalizedFields($mappedRow);

            if ($normalized['no_resi_awb'] !== '' || $normalized['no_sj'] !== '') {
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    protected function sheetLooksLikeInvoice(string $filePath, string $sheetPath, array $sharedStrings): bool
    {
        $seen = 0;

        foreach ($this->reader->iterateRows($filePath, $sheetPath, $sharedStrings) as $cells) {
            $seen++;

            if ($this->textLooksLikeInvoiceHeader(strtolower(implode(' ', $cells)))) {
                return true;
            }

            if ($seen >= 15) {
                break;
            }
        }

        return false;
    }

    protected function textLooksLikeInvoiceHeader(string $joined): bool
    {
        $hasWaybill = str_contains($joined, 'waybill');
        $isDepotLog = ! $hasWaybill && str_contains($joined, 'nomor sj')
            && (str_contains($joined, 'depo') || str_contains($joined, 'berat'));

        if ($isDepotLog) {
            return false;
        }

        return str_contains($joined, 'shipment')
            || str_contains($joined, 'tariff')
            || str_contains($joined, 'tarif')
            || str_contains($joined, 'chargeable')
            || str_contains($joined, 'ongkir')
            || str_contains($joined, 'waybill')
            || (str_contains($joined, 'resi') && str_contains($joined, 'biaya'))
            || (str_contains($joined, 'tujuan') && str_contains($joined, 'tagihan'));
    }

    /**
     * Normalkan nama kolom header dan bedakan kolom kembar seperti "KOTA" setelah "PENERIMA" (kota tujuan)
     * vs "KOTA" setelah "PENGIRIM" (kota asal) supaya tidak saling menimpa.
     *
     * @param  array<int|string, mixed>  $cells
     * @return array<int|string, string>
     */
    protected function normalizeHeaderColumns(array $cells): array
    {
        $headerMap = [];
        $used = [];
        $previousHeader = '';

        foreach ($cells as $column => $name) {
            $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $name)));

            if ($normalized === 'kota' || $normalized === 'city') {
                if (str_contains($previousHeader, 'pengirim') || str_contains($previousHeader, 'sender') || str_contains($previousHeader, 'shipper') || str_contains($previousHeader, 'asal')) {
                    $normalized = 'kota asal';
                } elseif (str_contains($previousHeader, 'penerima') || str_contains($previousHeader, 'receiver') || str_contains($previousHeader, 'consignee') || str_contains($previousHeader, 'tujuan')) {
                    $normalized = 'kota tujuan';
                }
            }

            if ($normalized !== '') {
                $previousHeader = $normalized;
            }

            $key = $normalized;
            if ($key !== '' && isset($used[$key])) {
                $suffix = 2;
                while (isset($used[$key.'_'.$suffix])) {
                    $suffix++;
                }
                $key .= '_'.$suffix;
            }

            if ($key !== '') {
                $used[$key] = true;
            }

            $headerMap[$column] = $key;
        }

        return $headerMap;
    }

    /**
     * Map flexible headers to standard reconciliation fields.
     */
    protected function mapNormalizedFields(array $row): array
    {
        $res = [
            'no_resi_awb' => '',
            'no_sj' => '',
            'shipment_date' => null,
            'recipient_name' => '',
            'origin_depo' => '',
            'destination_city' => '',
            'service_type' => '',
            'gross_weight' => 0.0,
            'billed_weight' => 1.0,
            'billed_rate' => 0.0,
            'billed_insurance' => 0.0,
            'billed_total' => 0.0,
            'invoice_badan_usaha' => '',
            'invoice_reff_note' => '',
            'invoice_brand' => '',
            'invoice_nominal_sj' => 0.0,
        ];

        $filled = [];
        $koli = 0.0;

        foreach ($row as $key => $val) {
            $field = $this->fieldForHeader((string) $key);
            $v = trim((string) $val);

            // Kolom pertama yang cocok dipakai, kecuali total: kolom total paling kanan adalah total akhir tagihan
            if ($field === null || $v === '' || (isset($filled[$field]) && $field !== 'billed_total')) {
                continue;
            }

            if ($field === 'koli') {
                $koli = (float) str_replace(',', '.', $v);
                $filled['koli'] = true;

                continue;
            }

            $filled[$field] = true;
            $res[$field] = match ($field) {
                'no_resi_awb', 'no_sj' => $this->reader->normalizeIdentifier($v),
                'shipment_date' => $this->parseDateValue($v),
                'recipient_name' => strtoupper($v),
                'gross_weight', 'billed_weight' => (float) str_replace(',', '.', $v),
                'billed_rate', 'billed_insurance', 'billed_total', 'invoice_nominal_sj' => Rupiah::parse($v),
                default => $v,
            };
        }

        // Untuk ekspedisi bertarif per koli (mis. Karya Mandiri) yang tidak punya kolom berat (kg),
        // jumlah koli dipakai sebagai pengali tarif.
        if (! isset($filled['billed_weight']) && $koli > 0) {
            $res['billed_weight'] = $koli;
        }

        return $res;
    }

    /**
     * Parse date value from Excel/CSV (serial number or various string date formats) into Y-m-d.
     */
    protected function parseDateValue(mixed $val): ?string
    {
        if (blank($val)) {
            return null;
        }

        if (is_numeric($val) && (float) $val > 20000 && (float) $val < 80000) {
            try {
                return Carbon::instance(Date::excelToDateTimeObject((float) $val))->toDateString();
            } catch (\Throwable) {
            }
        }

        $str = trim((string) $val);

        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'j-M-y', 'd-M-y', 'd M Y', 'd F Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $str)->toDateString();
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($str)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Kolom invoice ekspedisi => field rekonsiliasi. Urutan pengecekan penting: "Tariff Total" adalah total,
     * bukan tarif per kg; "CEK TARIF" dan keterangan adalah catatan admin, bukan data tagihan.
     */
    protected function fieldForHeader(string $header): ?string
    {
        $h = strtolower(trim((string) preg_replace('/\s+/', ' ', $header)));
        // "Berat (kg)", "Gross Weight"; bukan "Tarif/Kg"
        $isWeight = str_contains($h, 'berat') || str_contains($h, 'weight') || str_contains($h, 'chargeable')
            || str_contains($h, '(kg)') || $h === 'kg';

        return match (true) {
            $h === '' || str_contains($h, 'cek') || str_contains($h, 'ket') => null,
            str_contains($h, 'tanggal') || str_contains($h, 'tgl') || $h === 'date' => 'shipment_date',
            str_contains($h, 'penerima') || str_contains($h, 'receiver') || str_contains($h, 'dealer') || str_contains($h, 'toko') || str_contains($h, 'consignee') => 'recipient_name',
            str_contains($h, 'pengirim') || str_contains($h, 'sender') => 'invoice_badan_usaha',
            str_contains($h, 'nominal') || $h === 'ammount' || $h === 'amount' => 'invoice_nominal_sj',
            str_contains($h, 'badan usaha') => 'invoice_badan_usaha',
            str_contains($h, 'reffnote') || str_contains($h, 'reff note') || $h === 'reff' => 'invoice_reff_note',
            $h === 'brand' || str_starts_with($h, 'brand ') => 'invoice_brand',
            str_contains($h, 'resi') || str_contains($h, 'awb') || str_contains($h, 'shipment') || str_contains($h, 'waybill') || in_array($h, ['cn', 'connote', 'stt'], true) => 'no_resi_awb',
            (bool) preg_match('/\b(nomor|no)\.?\s*sj\b|surat jalan|faktur|^sj$/', $h) => 'no_sj',
            str_contains($h, 'asal') || str_contains($h, 'origin') || str_contains($h, 'depo') => 'origin_depo',
            str_contains($h, 'service') || str_contains($h, 'layanan') => 'service_type',
            str_contains($h, 'asuransi') || str_contains($h, 'insurance') => 'billed_insurance',
            $isWeight && (str_contains($h, 'gross') || str_contains($h, 'aktual') || str_contains($h, 'actual')) => 'gross_weight',
            $isWeight => 'billed_weight',
            in_array($h, ['koli', 'colly', 'colli', 'qty koli', 'jml koli'], true) => 'koli',
            str_contains($h, 'total') || str_contains($h, 'ongkir') || str_contains($h, 'jumlah') || str_contains($h, 'biaya') => 'billed_total',
            str_contains($h, 'tarif') || str_contains($h, 'rate') || str_contains($h, 'harga') => 'billed_rate',
            str_contains($h, 'tujuan') || str_contains($h, 'kota') || str_contains($h, 'dest') => 'destination_city',
            default => null,
        };
    }

    /**
     * Tarif Kontrak aktif ekspedisi, diindeks per "ASAL|TUJUAN" (kota maupun kecamatan tujuan).
     *
     * @return array<string, list<ExpeditionRateCard>>
     */
    protected function rateCardIndex(?int $expeditionId): array
    {
        $index = [];

        $cards = ExpeditionRateCard::where('expedition_id', $expeditionId)->where('is_active', true)->get();

        foreach ($cards as $card) {
            $destinations = array_unique(array_filter([$this->upper($card->destination_city), $this->upper($card->destination_district)]));

            foreach ($destinations as $destination) {
                $index[$this->upper($card->origin_depo).'|'.$destination][] = $card;
            }
        }

        return $index;
    }

    /**
     * Tarif termurah untuk rute & layanan; ejaan tujuan dicoba berurutan.
     *
     * @param  array<string, list<ExpeditionRateCard>>  $index
     * @param  list<string>  $destinations
     */
    protected function findRateCard(array $index, ?string $origin, array $destinations, ?string $service): ?ExpeditionRateCard
    {
        if ($origin === null) {
            return null;
        }

        foreach ($destinations as $destination) {
            $candidates = array_filter(
                $index[$origin.'|'.$destination] ?? [],
                fn (ExpeditionRateCard $card): bool => $service === null || $this->normalizeService($card->service_type) === $service,
            );

            if ($candidates !== []) {
                usort($candidates, fn (ExpeditionRateCard $a, ExpeditionRateCard $b): int => (float) $a->rate_per_kg <=> (float) $b->rate_per_kg);

                return $candidates[0];
            }
        }

        return null;
    }

    /**
     * Kota asal di Tarif Kontrak dari asal di invoice atau cabang pengiriman, misal "JAKARTA PIK" → "JAKARTA".
     *
     * @param  list<?string>  $candidates
     * @param  list<string>  $origins
     */
    protected function resolveOrigin(array $candidates, array $origins): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = $this->upper($candidate);

            if ($candidate === '') {
                continue;
            }

            if (in_array($candidate, $origins, true)) {
                return $candidate;
            }

            $contained = array_filter($origins, fn (string $origin): bool => str_contains($candidate, $origin));

            if ($contained !== []) {
                usort($contained, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

                return $contained[0];
            }
        }

        return null;
    }

    /**
     * Kota tujuan yang dicari di Tarif Kontrak, misal "DEPOK-DEPOK JAWA BARAT" → "DEPOK".
     *
     * @param  list<?string>  $values
     * @return list<string>
     */
    protected function destinationKeys(array $values): array
    {
        $keys = [];

        foreach ($values as $value) {
            $raw = $this->upper($value);
            $city = $this->upper(explode('-', (string) $value)[0]);

            if ($raw !== '') {
                $keys[] = $raw;
            }

            if ($city !== '') {
                // Ejaan apa adanya dulu (Tarif Kontrak 21 Express memakai "JOGYAKARTA"), lalu ejaan bakunya
                $keys[] = $city;
                $keys[] = self::DESTINATION_ALIASES[$city] ?? $city;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Layanan invoice/Tarif Kontrak: DARAT, UDARA, LAUT, atau null kalau tidak disebut.
     * "ECO" di invoice 21 Express sama dengan tarif UDARA di pricelist.
     */
    protected function normalizeService(?string $service): ?string
    {
        $s = $this->upper($service);

        return match (true) {
            $s === '' => null,
            str_contains($s, 'UDARA') || str_contains($s, 'AIR') || str_contains($s, 'PESAWAT') || str_contains($s, 'ECO') => 'UDARA',
            str_contains($s, 'LAUT') || str_contains($s, 'SEA') || str_contains($s, 'KAPAL') => 'LAUT',
            default => 'DARAT',
        };
    }

    /**
     * Pre-load Data Pengiriman berdasarkan No. Resi / AWB (kunci utama pencocokan).
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return array<string, list<CsaShipment>>
     */
    protected function loadShipmentsByResi(array $rawItems): array
    {
        $lookup = [];

        foreach ($rawItems as $item) {
            $resi = $this->reader->normalizeIdentifier((string) ($item['no_resi_awb'] ?? ''));

            if (! $this->isValidIdentifier($resi)) {
                continue;
            }

            $lookup[$resi] = true;
            $lookup[strtoupper($resi)] = true;
            $lookup[strtolower($resi)] = true;
        }

        if ($lookup === []) {
            return [];
        }

        $grouped = [];

        foreach (array_chunk(array_keys($lookup), 500) as $chunk) {
            $rows = CsaShipment::query()
                ->whereIn('no_resi_awb', $chunk)
                ->get([
                    'id', 'no_resi_awb', 'no_sj', 'badan_usaha', 'reff_note', 'brand',
                    'total_nominal_sj', 'berat', 'qty_koli', 'nama_ekspedisi', 'target_sheet', 'nama_gudang',
                ]);

            foreach ($rows as $row) {
                $key = $this->upper($row->no_resi_awb);
                if ($this->isValidIdentifier($key)) {
                    $grouped[$key][] = $row;
                }
            }
        }

        return $grouped;
    }

    /**
     * Pre-load Data Pengiriman berdasarkan Nomor SJ (kunci cadangan bila No. Resi belum diinput di Data Pengiriman).
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return array<string, list<CsaShipment>>
     */
    protected function loadShipmentsBySj(array $rawItems): array
    {
        $lookup = [];

        foreach ($rawItems as $item) {
            foreach ($this->splitIdentifiers((string) ($item['no_sj'] ?? '')) as $sj) {
                $lookup[$sj] = true;
                $lookup[strtoupper($sj)] = true;
            }
        }

        if ($lookup === []) {
            return [];
        }

        $grouped = [];

        foreach (array_chunk(array_keys($lookup), 500) as $chunk) {
            $rows = CsaShipment::query()
                ->whereIn('no_sj', $chunk)
                ->get([
                    'id', 'no_resi_awb', 'no_sj', 'badan_usaha', 'reff_note', 'brand',
                    'total_nominal_sj', 'berat', 'qty_koli', 'nama_ekspedisi', 'target_sheet', 'nama_gudang',
                ]);

            foreach ($rows as $row) {
                $key = $this->upper($row->no_sj);
                if ($this->isValidIdentifier($key)) {
                    $grouped[$key][] = $row;
                }
            }
        }

        return $grouped;
    }

    /**
     * Pre-load Data Pengiriman untuk Smart Fallback (pencocokan tanggal + toko + kota)
     * saat nomor resi dan nomor SJ keduanya tidak cocok atau tidak tersedia di invoice.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @param  list<string>  $origins
     * @return Collection<int, CsaShipment>
     */
    protected function loadFallbackShipments(array $rawItems, FreightInvoice $invoice, array $origins): Collection
    {
        $dates = collect($rawItems)
            ->pluck('shipment_date')
            ->filter()
            ->values();

        if ($dates->isEmpty()) {
            return collect();
        }

        $minDate = Carbon::parse($dates->min())->subDays(3)->toDateString();
        $maxDate = Carbon::parse($dates->max())->addDays(3)->toDateString();

        $origin = $invoice->origin_city ?: ($origins[0] ?? null);

        $query = CsaShipment::query()
            ->whereBetween('tanggal_kirim', [$minDate.' 00:00:00', $maxDate.' 23:59:59'])
            ->whereNotNull('tujuan_dealer')
            ->where('tujuan_dealer', '!=', '');

        if ($origin !== null && $origin !== '') {
            $upperOrigin = strtoupper(trim($origin));
            $query->where(function ($q) use ($upperOrigin) {
                $q->where('target_sheet', $upperOrigin)
                    ->orWhere('nama_gudang', $upperOrigin)
                    ->orWhere('reff_note', 'LIKE', "%{$upperOrigin}%");
            });
        }

        return $query->get([
            'id', 'no_resi_awb', 'no_sj', 'badan_usaha', 'reff_note', 'brand',
            'tujuan_dealer', 'nama_kota', 'tanggal_kirim', 'total_nominal_sj',
            'berat', 'qty_koli', 'nama_ekspedisi', 'target_sheet', 'nama_gudang',
        ]);
    }

    /**
     * Cocokkan baris invoice ke Data Pengiriman berdasarkan kombinasi:
     * Tanggal Kirim (+/- 2 hari) + Kesamaan Nama Toko/Dealer + Kesamaan Kota Tujuan.
     *
     * @param  array<string, mixed>  $item
     * @param  Collection<int, CsaShipment>  $fallbackShipments
     */
    protected function matchByDealerAndDate(
        array $item,
        ?string $originDepo,
        ?Expedition $expedition,
        Collection $fallbackShipments
    ): ?CsaShipment {
        $itemDate = $item['shipment_date'] ?? null;
        $recipientName = strtoupper(trim((string) ($item['recipient_name'] ?? '')));
        $destinationCity = strtoupper(trim((string) ($item['destination_city'] ?? '')));

        if ($itemDate === null || $recipientName === '') {
            return null;
        }

        // Singkirkan kata-kata umum badan usaha/toko agar pencarian fokus pada nama unik dealer
        $stopWords = ['CV', 'PT', 'UD', 'TOKO', 'CELL', 'SELULAR', 'CELLULAR', 'ACCESSORIES', 'ACC', 'STORE', 'SHOP', 'JAYA', 'ABADI', 'BARU', 'KEC', 'KAB', 'DESA'];
        $words = array_values(array_filter(
            preg_split('/[\s,\.\-]+/', $recipientName) ?: [],
            fn (string $w): bool => strlen($w) >= 3 && ! in_array($w, $stopWords, true),
        ));

        if ($words === [] && strlen($recipientName) < 3) {
            return null;
        }

        $bestCandidate = null;
        $bestScore = 0;

        foreach ($fallbackShipments as $cand) {
            if ($cand->tanggal_kirim === null) {
                continue;
            }

            $candDate = Carbon::parse($cand->tanggal_kirim)->toDateString();
            $diffDays = abs(Carbon::parse($candDate)->diffInDays(Carbon::parse($itemDate), false));

            // Maksimal selisih 2 hari kalender
            if ($diffDays > 2) {
                continue;
            }

            // Jika ekspedisi pada data pengiriman sudah terisi, pastikan kompatibel
            if ($expedition !== null && filled($cand->nama_ekspedisi) && ! $this->expeditionMatches($expedition, $cand->nama_ekspedisi)) {
                continue;
            }

            $candDealer = strtoupper(trim((string) $cand->tujuan_dealer));
            $candCity = strtoupper(trim((string) $cand->nama_kota));

            $score = 0;

            // 1. Bonus kecocokan kota tujuan
            if ($destinationCity !== '' && $candCity !== '') {
                if (str_contains($candCity, $destinationCity) || str_contains($destinationCity, $candCity)) {
                    $score += 15;
                }
            }

            // 2. Kecocokan token nama toko unik
            foreach ($words as $w) {
                if (str_contains($candDealer, $w)) {
                    $score += 10;
                }
            }

            // 3. Kecocokan nama penerima penuh (substring)
            if (strlen($recipientName) >= 4 && str_contains($candDealer, $recipientName)) {
                $score += 20;
            }

            // 4. Bonus tanggal persis sama
            if ($candDate === $itemDate) {
                $score += 10;
            } elseif ($diffDays <= 1) {
                $score += 5;
            }

            // Ambang batas: minimal ada kecocokan nama toko (10) + kota/tanggal (15) = 25
            if ($score >= 25 && $score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $cand;
            }
        }

        return $bestCandidate;
    }

    /**
     * Cocokkan satu baris invoice ke Data Pengiriman (prioritas No. Resi, lalu fallback No. SJ yang terverifikasi konteksnya,
     * dan smart fallback tanggal + toko + kota jika resi/SJ tidak cocok) dan gabungkan field Badan Usaha, Reffnote, Brand,
     * No. SJ, Total Nominal SJ, serta Berat Gudang.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, list<CsaShipment>>  $shipmentsByResi
     * @param  array<string, list<CsaShipment>>  $shipmentsBySj
     * @param  Collection<int, CsaShipment>|null  $fallbackShipments
     * @return array{
     *     csa_shipment_id: ?int,
     *     matched_by: ?string,
     *     nama_ekspedisi: ?string,
     *     no_sj: ?string,
     *     badan_usaha: ?string,
     *     reff_note: ?string,
     *     brand: ?string,
     *     tujuan_dealer: ?string,
     *     nominal_sj: float,
     *     weight: ?float,
     * }
     */
    protected function matchAndSummarizeShipment(
        string $noResi,
        string $noSj,
        ?string $originDepo,
        array $item,
        ?Expedition $expedition,
        array $shipmentsByResi,
        array $shipmentsBySj,
        ?Collection $fallbackShipments = null,
    ): array {
        $empty = [
            'csa_shipment_id' => null,
            'matched_by' => null,
            'nama_ekspedisi' => null,
            'no_sj' => null,
            'badan_usaha' => null,
            'reff_note' => null,
            'brand' => null,
            'tujuan_dealer' => null,
            'nominal_sj' => 0.0,
            'weight' => null,
        ];

        $matchedBy = null;
        $candidates = [];

        // 1. Kunci utama: No. Resi / AWB
        $resiKey = $this->upper($noResi);
        if ($this->isValidIdentifier($resiKey) && isset($shipmentsByResi[$resiKey])) {
            $candidates = $shipmentsByResi[$resiKey];

            // Jika nomor resi pendek bentrok antar ekspedisi, prioritaskan yang ekspedisinya cocok
            if ($expedition !== null && count($candidates) > 1) {
                $byExp = array_values(array_filter(
                    $candidates,
                    fn (CsaShipment $s): bool => $this->expeditionMatches($expedition, $s->nama_ekspedisi),
                ));

                if ($byExp !== []) {
                    $candidates = $byExp;
                }
            }

            if ($originDepo !== null && count($candidates) > 1) {
                $byOrigin = array_values(array_filter(
                    $candidates,
                    fn (CsaShipment $s): bool => $this->shipmentMatchesOrigin($originDepo, $s),
                ));

                if ($byOrigin !== []) {
                    $candidates = $byOrigin;
                }
            }

            $matchedBy = 'resi';
        }

        // 2. Fallback: No. SJ (wajib terverifikasi konteks depo/badan usaha/brand/ekspedisi agar tidak tertukar antar depo)
        if ($candidates === []) {
            $sjTokens = $this->splitIdentifiers($noSj);
            $sjCandidates = [];

            foreach ($sjTokens as $token) {
                $key = $this->upper($token);
                if (isset($shipmentsBySj[$key])) {
                    foreach ($shipmentsBySj[$key] as $row) {
                        $sjCandidates[$row->id] = $row;
                    }
                }
            }

            $sjCandidates = array_values($sjCandidates);

            if ($sjCandidates !== []) {
                $contextual = array_values(array_filter(
                    $sjCandidates,
                    fn (CsaShipment $s): bool => $this->shipmentMatchesContext($s, $originDepo, $item, $expedition),
                ));

                $hasContextClues = filled($originDepo)
                    || filled($item['invoice_badan_usaha'] ?? '')
                    || filled($item['invoice_brand'] ?? '')
                    || filled($item['invoice_reff_note'] ?? '');

                if ($contextual !== []) {
                    $candidates = $contextual;
                    $matchedBy = 'sj';
                } elseif (! $hasContextClues && count($sjCandidates) === 1) {
                    $candidates = $sjCandidates;
                    $matchedBy = 'sj';
                }
            }
        }

        // 3. Smart Fallback: Tanggal Kirim + Nama Toko/Penerima + Kota Tujuan
        if ($candidates === [] && $fallbackShipments !== null && $fallbackShipments->isNotEmpty()) {
            $fallback = $this->matchByDealerAndDate($item, $originDepo, $expedition, $fallbackShipments);
            if ($fallback !== null) {
                $candidates = [$fallback];
                $matchedBy = 'dealer_date';
            }
        }

        if ($candidates === []) {
            return $empty;
        }

        $deduped = $this->deduplicateShipments($candidates);
        $first = $deduped[0];

        // Backfill No. Resi ke Data Pengiriman jika belum ada dan nomor resi invoice valid
        if ($matchedBy === 'dealer_date' && ! $this->isValidIdentifier((string) $first->no_resi_awb) && $this->isValidIdentifier($noResi)) {
            $first->update(['no_resi_awb' => $noResi]);
        }

        $sjs = [];
        $badanUsaha = [];
        $reffNotes = [];
        $brands = [];
        $nominalSum = 0.0;
        $weightSum = 0.0;
        $hasWeight = false;

        foreach ($deduped as $shipment) {
            $sjVal = trim((string) $shipment->no_sj);
            if ($this->isValidIdentifier($sjVal)) {
                $sjs[] = $sjVal;
            }

            $bu = trim((string) $shipment->badan_usaha);
            if ($bu !== '') {
                $badanUsaha[] = $bu;
            }

            $reff = trim((string) $shipment->reff_note);
            if ($reff !== '') {
                $reffNotes[] = $reff;
            }

            $brand = trim((string) $shipment->brand);
            if ($brand !== '') {
                $brands[] = $brand;
            }

            $nominalSum += (float) $shipment->total_nominal_sj;

            if ((float) $shipment->berat > 0) {
                $weightSum += (float) $shipment->berat;
                $hasWeight = true;
            }
        }

        return [
            'csa_shipment_id' => $first->id,
            'matched_by' => $matchedBy,
            'nama_ekspedisi' => $first->nama_ekspedisi,
            'no_sj' => $sjs !== [] ? implode(', ', array_values(array_unique($sjs))) : null,
            'badan_usaha' => $badanUsaha !== [] ? implode(', ', array_values(array_unique($badanUsaha))) : null,
            'reff_note' => $reffNotes !== [] ? implode(', ', array_values(array_unique($reffNotes))) : null,
            'brand' => $brands !== [] ? implode(', ', array_values(array_unique($brands))) : null,
            'tujuan_dealer' => $first->tujuan_dealer ?: null,
            'nominal_sj' => round($nominalSum, 2),
            'weight' => $hasWeight ? round($weightSum, 2) : null,
        ];
    }

    /**
     * Buang baris duplikat identik dari import spreadsheet lama agar berat & nominal tidak terhitung ganda.
     *
     * @param  list<CsaShipment>  $shipments
     * @return list<CsaShipment>
     */
    protected function deduplicateShipments(array $shipments): array
    {
        $unique = [];

        foreach ($shipments as $s) {
            $fingerprint = implode('|', [
                $this->upper($s->no_sj),
                $this->upper($s->badan_usaha),
                $this->upper($s->reff_note),
                $this->upper($s->brand),
                number_format((float) $s->berat, 2, '.', ''),
                number_format((float) $s->total_nominal_sj, 2, '.', ''),
            ]);

            $unique[$fingerprint] ??= $s;
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function shipmentMatchesContext(CsaShipment $shipment, ?string $originDepo, array $item, ?Expedition $expedition): bool
    {
        if ($originDepo !== null && $this->shipmentMatchesOrigin($originDepo, $shipment)) {
            return true;
        }

        $invBu = $this->normalizeAlnum((string) ($item['invoice_badan_usaha'] ?? ''));
        $shipBu = $this->normalizeAlnum((string) $shipment->badan_usaha);
        if ($invBu !== '' && $shipBu !== '' && (str_contains($shipBu, $invBu) || str_contains($invBu, $shipBu))) {
            return true;
        }

        $invReff = $this->normalizeAlnum((string) ($item['invoice_reff_note'] ?? ''));
        $shipReff = $this->normalizeAlnum((string) $shipment->reff_note);
        if ($invReff !== '' && $shipReff !== '' && (str_contains($shipReff, $invReff) || str_contains($invReff, $shipReff))) {
            return true;
        }

        if ($expedition !== null && $this->expeditionMatches($expedition, $shipment->nama_ekspedisi)) {
            return true;
        }

        return false;
    }

    protected function shipmentMatchesOrigin(string $originDepo, CsaShipment $shipment): bool
    {
        $origin = $this->upper($originDepo);
        if ($origin === '') {
            return false;
        }

        foreach ([$shipment->target_sheet, $shipment->nama_gudang, $shipment->reff_note] as $field) {
            $val = $this->upper($field);
            if ($val !== '' && (str_contains($val, $origin) || str_contains($origin, $val))) {
                return true;
            }
        }

        return false;
    }

    protected function expeditionMatches(Expedition $expedition, ?string $shipmentExpedition): bool
    {
        $actual = $this->normalizeAlnum((string) $shipmentExpedition);
        if ($actual === '') {
            return false;
        }

        $target = $this->normalizeAlnum($expedition->name.' '.$expedition->code);

        $families = [
            ['JNT', 'JT', 'JTCARGO'],
            ['RAX', 'RESTUABADI'],
            ['21EXPRES', '21EXPRESS'],
            ['KARYAMANDIRI', 'KARYAMANDI'],
            ['SENTRALCARGO', 'SENTRALCAR'],
            ['IDEXPRES', 'IDEXPRESS'],
            ['SICEPAT'],
            ['JNE'],
        ];

        foreach ($families as $tokens) {
            $targetInFamily = collect($tokens)->contains(fn (string $t): bool => str_contains($target, $t));
            $actualInFamily = collect($tokens)->contains(fn (string $t): bool => str_contains($actual, $t));

            if ($targetInFamily && $actualInFamily) {
                return true;
            }
        }

        $expName = $this->normalizeAlnum($expedition->name);

        return $expName !== '' && (str_contains($actual, $expName) || str_contains($expName, $actual));
    }

    /**
     * @return list<string>
     */
    protected function splitIdentifiers(string $raw): array
    {
        $tokens = [];

        foreach (preg_split('/[\s,;\n\/]+/', $raw) ?: [] as $part) {
            $clean = trim($part);
            if ($this->isValidIdentifier($clean) && strlen($clean) >= 4) {
                $tokens[] = $clean;
            }
        }

        return array_values(array_unique($tokens));
    }

    protected function isValidIdentifier(string $value): bool
    {
        return ! in_array($this->upper($value), self::PLACEHOLDER_IDENTIFIERS, true);
    }

    private function normalizeAlnum(string $value): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($value));
    }

    private function upper(?string $value): string
    {
        return strtoupper(trim((string) $value));
    }

    private function rupiah(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function kg(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

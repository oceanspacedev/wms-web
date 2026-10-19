<?php

namespace App\Services;

use App\Models\CsaShipment;
use App\Models\ExpeditionRateCard;
use App\Models\FreightInvoice;
use App\Models\FreightInvoiceItem;
use App\Support\Rupiah;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    ];

    public function __construct(private XlsxReader $reader = new XlsxReader) {}

    /**
     * Cocokkan setiap baris invoice ekspedisi dengan pengiriman riil seluruh depo (Data Pengiriman)
     * dan dengan Tarif Kontrak ekspedisi tersebut.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     */
    public function reconcile(FreightInvoice $invoice, array $rawItems): FreightInvoice
    {
        return DB::transaction(function () use ($invoice, $rawItems) {
            // Delete previous items if re-auditing
            $invoice->items()->delete();

            $totalBilled = 0.0;
            $totalApproved = 0.0;
            $totalDiscrepancy = 0.0;
            $matchedCount = 0;
            $discrepancyCount = 0;
            $unrecognizedCount = 0;
            $duplicateCount = 0;

            $rateCards = $this->rateCardIndex($invoice->expedition_id);
            $origins = collect($rateCards)->flatten()->map(fn (ExpeditionRateCard $card): string => $this->upper($card->origin_depo))->unique()->values()->all();

            // Pre-load previously approved resi numbers to catch duplicate invoicing
            $previousResi = FreightInvoiceItem::where('freight_invoice_id', '!=', $invoice->id)
                ->whereHas('invoice', fn ($q) => $q->whereIn('status', ['approved', 'paid']))
                ->pluck('freight_invoice_id', 'no_resi_awb')
                ->toArray();

            $itemsToInsert = [];
            $now = now();
            $shipmentsByResi = $this->shipmentsByIdentifier($rawItems, 'no_resi_awb');
            $shipmentsBySj = $this->shipmentsByIdentifier($rawItems, 'no_sj');

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
                }

                $totalBilled += $billedTotal;

                $auditStatus = 'matched';
                $auditNotes = [];
                $actualShipment = null;
                $actualWeight = null;
                $expectedTotal = $billedTotal;
                $agreedRate = 0.0;
                $agreedInsurance = 0.0;
                $originDepo = null;

                // 1. DUPLICATE CHECK
                if (! empty($noResi) && isset($previousResi[$noResi])) {
                    $auditStatus = 'duplicate';
                    $auditNotes[] = "Resi sudah pernah ditagihkan pada Faktur #{$previousResi[$noResi]}";
                    $expectedTotal = 0.0;
                    $duplicateCount++;
                } else {
                    $shipments = ($noResi !== '' ? $shipmentsByResi->get($noResi) : null)
                        ?? ($noSj !== '' ? $shipmentsBySj->get($noSj) : null);
                    $actualShipment = $shipments?->first();

                    if (! $actualShipment) {
                        $auditStatus = 'unrecognized';
                        $auditNotes[] = 'Resi/SJ tidak ditemukan pada riil pengiriman seluruh depo Ocean Space';
                        $expectedTotal = 0.0;
                        $unrecognizedCount++;
                    } else {
                        $originDepo = $this->resolveOrigin([$item['origin_depo'] ?? '', $actualShipment->target_sheet, $actualShipment->nama_gudang], $origins);
                        // Kota di Data Pengiriman hanya dipakai kalau invoice tidak menyebut tujuan
                        $destinations = $this->destinationKeys([trim((string) ($item['destination_city'] ?? '')) ?: $actualShipment->nama_kota]);
                        $destinationLabel = $destinations[0] ?? '-';

                        // Berat dasar: berat gudang di Data Pengiriman; kalau kosong, berat timbang ekspedisi (gross)
                        $recordedWeight = $this->recordedWeight($shipments);
                        $weightBasis = $recordedWeight > 0 ? $recordedWeight : $grossWeight;
                        $actualWeight = $weightBasis > 0 ? $weightBasis : null;

                        $insurancePercent = 0.0;
                        $rateCard = $this->findRateCard($rateCards, $originDepo, $destinations, $service);

                        if ($rateCard) {
                            $agreedRate = (float) $rateCard->rate_per_kg;
                            $insurancePercent = (float) $rateCard->insurance_rate_percent;
                            $agreedInsurance = round((float) $actualShipment->total_nominal_sj * ($insurancePercent / 100), 2);

                            $hasRateIssue = $billedRate > 0 && $billedRate > $agreedRate + 0.5;
                            $hasWeightIssue = false;
                            $chargeWeight = $billedWeight;

                            if ($weightBasis > 0) {
                                // Berat ditagih = berat dibulatkan ke atas, minimal min. kg di Tarif Kontrak
                                $expectedWeight = max(ceil(round($weightBasis, 2)), (float) $rateCard->min_kg);
                                $chargeWeight = min($billedWeight, $expectedWeight);

                                // Toleransi selisih timbangan gudang vs ekspedisi: 1 kg atau 10%
                                if ($billedWeight > $expectedWeight + max(1.0, $expectedWeight * 0.1)) {
                                    $hasWeightIssue = true;
                                    $auditNotes[] = "Berat Lebih: Ditagih {$billedWeight} kg vs seharusnya {$expectedWeight} kg (berat {$weightBasis} kg, min. {$this->kg((float) $rateCard->min_kg)} kg)";
                                }
                            } else {
                                $auditNotes[] = 'Berat gudang belum tercatat, markup berat tidak dinilai';
                            }

                            if ($hasRateIssue) {
                                $auditNotes[] = 'Tarif Melebihi Kontrak: Ditagih Rp '.$this->rupiah($billedRate).'/kg vs Tarif Kontrak Rp '.$this->rupiah($agreedRate).'/kg (+Rp '.$this->rupiah($billedRate - $agreedRate).'/kg)';
                            } elseif ($billedRate > 0 && $billedRate < $agreedRate - 0.5) {
                                $auditNotes[] = 'Lebih murah dari Tarif Kontrak (Rp '.$this->rupiah($agreedRate).'/kg)';
                            }

                            $contractTotal = round($chargeWeight * ($billedRate > 0 ? min($billedRate, $agreedRate) : $agreedRate) + $agreedInsurance, 2);
                            $expectedTotal = min($billedTotal, $contractTotal);

                            $auditStatus = match (true) {
                                $hasWeightIssue && $hasRateIssue => 'discrepancy_both',
                                $hasWeightIssue => 'discrepancy_weight',
                                $hasRateIssue => 'discrepancy_rate',
                                $weightBasis <= 0 => 'missing_weight',
                                default => 'matched',
                            };
                        } elseif ($service !== null && $service !== 'DARAT'
                            && ($darat = $this->findRateCard($rateCards, $originDepo, $destinations, 'DARAT'))) {
                            // Layanan yang ditagih (mis. ECO/UDARA) belum ada tarifnya: bandingkan dengan tarif DARAT rute yang sama
                            $agreedRate = (float) $darat->rate_per_kg;
                            $daratWeight = max($weightBasis > 0 ? ceil(round($weightBasis, 2)) : $billedWeight, (float) $darat->min_kg);
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
                            $auditStatus = 'missing_rate';
                            $route = ($originDepo ?? '(asal tidak diketahui)').' → '.$destinationLabel.($service ? " ({$service})" : '');
                            $auditNotes[] = "Tarif Kontrak untuk rute {$route} belum terdaftar di sistem";
                            $expectedTotal = $billedTotal;
                        }

                        if ($auditStatus === 'matched') {
                            $matchedCount++;
                        } else {
                            $discrepancyCount++;
                        }
                    }
                }

                $discrepancyAmount = round(max(0, $billedTotal - $expectedTotal), 2);
                $totalDiscrepancy += $discrepancyAmount;
                $totalApproved += $expectedTotal;

                $itemsToInsert[] = [
                    'freight_invoice_id' => $invoice->id,
                    'csa_shipment_id' => $actualShipment?->id,
                    'no_resi_awb' => $noResi ?: ($noSj ?: '-'),
                    'no_sj' => $noSj ?: ($actualShipment?->no_sj ?? null),
                    'origin_depo' => $originDepo ?? (($item['origin_depo'] ?? '') ?: ($actualShipment?->target_sheet ?? null)),
                    'destination_city' => ($item['destination_city'] ?? '') ?: ($actualShipment?->nama_kota ?? null),
                    'service_type' => $billedService ?: null,
                    'billed_weight' => $billedWeight,
                    'actual_weight' => $actualWeight,
                    'weight_discrepancy' => $actualWeight !== null ? round($billedWeight - $actualWeight, 2) : 0.0,
                    'billed_rate' => $billedRate,
                    'agreed_rate' => $agreedRate,
                    'billed_insurance' => $billedInsurance,
                    'agreed_insurance' => $agreedInsurance,
                    'billed_total' => $billedTotal,
                    'expected_total' => $expectedTotal,
                    'discrepancy_amount' => $discrepancyAmount,
                    'audit_status' => $auditStatus,
                    'audit_notes' => implode(' | ', $auditNotes),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Batch insert items in chunks of 500
            foreach (array_chunk($itemsToInsert, 500) as $chunk) {
                DB::table('freight_invoice_items')->insert($chunk);
            }

            // Update parent invoice
            $invoice->update([
                'total_billed_amount' => $totalBilled,
                'total_approved_amount' => $totalApproved,
                'total_discrepancy_amount' => $totalDiscrepancy,
                'total_items_count' => count($itemsToInsert),
                'matched_count' => $matchedCount,
                'discrepancy_count' => $discrepancyCount,
                'unrecognized_count' => $unrecognizedCount,
                'duplicate_count' => $duplicateCount,
                'status' => 'audited',
            ]);

            return $invoice->fresh(['items']);
        });
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
                    $header = array_map(fn ($h) => strtolower(trim($h)), $data);

                    continue;
                }
                $row = array_combine($header, array_pad($data, count($header), ''));
                $rows[] = $this->mapNormalizedFields($row);
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
                    foreach ($cells as $column => $name) {
                        $headerMap[$column] = strtolower(trim($name));
                    }
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
        $isDepotLog = str_contains($joined, 'nomor sj')
            && (str_contains($joined, 'depo') || str_contains($joined, 'berat'));

        if ($isDepotLog) {
            return false;
        }

        return str_contains($joined, 'shipment')
            || str_contains($joined, 'tariff')
            || str_contains($joined, 'tarif')
            || str_contains($joined, 'chargeable')
            || str_contains($joined, 'ongkir')
            || (str_contains($joined, 'resi') && str_contains($joined, 'biaya'));
    }

    /**
     * Berat gudang untuk satu resi: jumlah berat semua SJ dalam resi itu (SJ yang tercatat dobel dihitung sekali).
     *
     * @param  Collection<int, CsaShipment>  $shipments
     */
    protected function recordedWeight(Collection $shipments): float
    {
        return (float) $shipments
            ->groupBy(fn (CsaShipment $shipment): string => $shipment->no_sj ?: 'id-'.$shipment->id)
            ->sum(fn (Collection $rows): float => (float) $rows->max('berat'));
    }

    /**
     * Data Pengiriman per nomor resi / SJ; satu resi bisa berisi beberapa SJ.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return Collection<string, Collection<int, CsaShipment>>
     */
    protected function shipmentsByIdentifier(array $rawItems, string $field): Collection
    {
        $column = $field === 'no_resi_awb' ? 'no_resi_awb' : 'no_sj';
        $keys = [];

        foreach ($rawItems as $item) {
            $value = $this->reader->normalizeIdentifier((string) ($item[$field] ?? ''));

            if ($value !== '') {
                $keys[] = $value;
            }
        }

        $keys = array_values(array_unique($keys));

        if ($keys === []) {
            return collect();
        }

        return CsaShipment::query()
            ->whereIn($column, $keys)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CsaShipment $shipment): string => $this->reader->normalizeIdentifier((string) $shipment->{$column}));
    }

    /**
     * Map flexible headers to standard reconciliation fields.
     */
    protected function mapNormalizedFields(array $row): array
    {
        $res = [
            'no_resi_awb' => '',
            'no_sj' => '',
            'origin_depo' => '',
            'destination_city' => '',
            'service_type' => '',
            'gross_weight' => 0.0,
            'billed_weight' => 1.0,
            'billed_rate' => 0.0,
            'billed_insurance' => 0.0,
            'billed_total' => 0.0,
        ];

        $filled = [];

        foreach ($row as $key => $val) {
            $field = $this->fieldForHeader((string) $key);
            $v = trim((string) $val);

            // Kolom pertama yang cocok dipakai, kecuali total: kolom total paling kanan adalah total akhir tagihan
            if ($field === null || $v === '' || (isset($filled[$field]) && $field !== 'billed_total')) {
                continue;
            }

            $filled[$field] = true;
            $res[$field] = match ($field) {
                'no_resi_awb', 'no_sj' => $this->reader->normalizeIdentifier($v),
                'gross_weight', 'billed_weight' => (float) str_replace(',', '.', $v),
                'billed_rate', 'billed_insurance', 'billed_total' => Rupiah::parse($v),
                default => $v,
            };
        }

        return $res;
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
            $h === '' || str_contains($h, 'cek') || str_contains($h, 'ket') || str_contains($h, 'nominal') => null,
            str_contains($h, 'resi') || str_contains($h, 'awb') || str_contains($h, 'shipment') || in_array($h, ['cn', 'connote', 'stt'], true) => 'no_resi_awb',
            (bool) preg_match('/\b(nomor|no)\.?\s*sj\b|surat jalan|faktur|^sj$/', $h) => 'no_sj',
            str_contains($h, 'asal') || str_contains($h, 'origin') || str_contains($h, 'depo') => 'origin_depo',
            str_contains($h, 'service') || str_contains($h, 'layanan') => 'service_type',
            str_contains($h, 'asuransi') || str_contains($h, 'insurance') => 'billed_insurance',
            $isWeight && (str_contains($h, 'gross') || str_contains($h, 'aktual') || str_contains($h, 'actual')) => 'gross_weight',
            $isWeight => 'billed_weight',
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
            $city = $this->upper(explode('-', (string) $value)[0]);

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

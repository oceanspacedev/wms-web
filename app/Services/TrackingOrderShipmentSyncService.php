<?php

namespace App\Services;

use App\Models\CsaShipment;
use App\Models\TrackingOrder;
use App\Models\WarehouseMapping;
use Illuminate\Support\Collection;

/**
 * Menyinkronkan data Surat Jalan (TrackingOrder) ke Data Pengiriman (CsaShipment) berdasarkan Nomor SJ.
 * Mengisi baris baru bila data CSA belum di-upload, serta melengkapi kolom kosong tanpa menimpa data CSA atau isian admin.
 */
class TrackingOrderShipmentSyncService
{
    /** @var array<string, ?WarehouseMapping> */
    private array $mappingCache = [];

    public function syncOrder(TrackingOrder $order): string
    {
        $noSj = trim((string) $order->no_sj);
        if ($noSj === '') {
            return 'skipped';
        }

        $payload = $this->extractPayload($order, $noSj);
        $existing = CsaShipment::query()->where('no_sj', $noSj)->get();

        if ($existing->isEmpty()) {
            CsaShipment::create([
                'source' => CsaShipment::SOURCE_SURAT_JALAN,
                'no_sj' => $noSj,
                'tanggal_order' => $payload['tanggal_order'],
                'tanggal_kirim' => $payload['tanggal_kirim'],
                'kode_gudang' => $payload['kode_gudang'],
                'nama_gudang' => $payload['nama_gudang'],
                'target_sheet' => $payload['target_sheet'],
                'tujuan_dealer' => $payload['tujuan_dealer'],
                'alamat_kirim' => $payload['alamat_kirim'],
                'nama_kota' => $payload['nama_kota'],
                'nama_ekspedisi' => $payload['nama_ekspedisi'],
                'reff_note' => $payload['reff_note'],
                'total_nominal_sj' => $payload['total_nominal_sj'],
                'qty_unit' => 0,
                'qty_koli' => 0,
                'berat' => 0,
                'status_pengiriman' => $payload['status_pengiriman'],
                'tanggal_diterima' => $payload['tanggal_diterima'],
                'is_synced' => false,
                'already_in_sheet' => false,
            ]);

            return 'created';
        }

        $updatedAny = false;
        foreach ($existing as $shipment) {
            if ($this->fillShipmentFromPayload($shipment, $payload)) {
                $updatedAny = true;
            }
        }

        return $updatedAny ? 'updated' : 'skipped';
    }

    /**
     * Sinkronisasi massal seluruh Surat Jalan ke Data Pengiriman (untuk data lama maupun setelah reset/upload).
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncAll(?string $cabangScope = null): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $normalizedScope = filled($cabangScope) ? strtoupper(trim((string) $cabangScope)) : null;

        CsaShipment::withoutLookupCacheFlush(function () use (&$stats, $normalizedScope): void {
            TrackingOrder::query()
                ->with('depo:id,kode,nama')
                ->whereNotNull('no_sj')
                ->where('no_sj', '!=', '')
                ->orderBy('id')
                ->chunkById(200, function (Collection $orders) use (&$stats, $normalizedScope): void {
                    $filtered = $normalizedScope === null
                        ? $orders
                        : $orders->filter(function (TrackingOrder $order) use ($normalizedScope): bool {
                            $payload = $this->extractPayload($order, trim((string) $order->no_sj));

                            return strtoupper(trim((string) $payload['target_sheet'])) === $normalizedScope;
                        });

                    $sjNumbers = $filtered
                        ->pluck('no_sj')
                        ->map(fn ($sj): string => trim((string) $sj))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    if ($sjNumbers === []) {
                        return;
                    }

                    $shipmentsBySj = CsaShipment::query()
                        ->whereIn('no_sj', $sjNumbers)
                        ->get()
                        ->groupBy(fn (CsaShipment $shipment): string => trim((string) $shipment->no_sj));

                    foreach ($filtered as $order) {
                        $noSj = trim((string) $order->no_sj);
                        if ($noSj === '') {
                            $stats['skipped']++;

                            continue;
                        }

                        $payload = $this->extractPayload($order, $noSj);
                        /** @var Collection<int, CsaShipment>|null $existing */
                        $existing = $shipmentsBySj->get($noSj);

                        if ($existing === null || $existing->isEmpty()) {
                            $created = CsaShipment::create([
                                'source' => CsaShipment::SOURCE_SURAT_JALAN,
                                'no_sj' => $noSj,
                                'tanggal_order' => $payload['tanggal_order'],
                                'tanggal_kirim' => $payload['tanggal_kirim'],
                                'kode_gudang' => $payload['kode_gudang'],
                                'nama_gudang' => $payload['nama_gudang'],
                                'target_sheet' => $payload['target_sheet'],
                                'tujuan_dealer' => $payload['tujuan_dealer'],
                                'alamat_kirim' => $payload['alamat_kirim'],
                                'nama_kota' => $payload['nama_kota'],
                                'nama_ekspedisi' => $payload['nama_ekspedisi'],
                                'reff_note' => $payload['reff_note'],
                                'total_nominal_sj' => $payload['total_nominal_sj'],
                                'qty_unit' => 0,
                                'qty_koli' => 0,
                                'berat' => 0,
                                'status_pengiriman' => $payload['status_pengiriman'],
                                'tanggal_diterima' => $payload['tanggal_diterima'],
                                'is_synced' => false,
                                'already_in_sheet' => false,
                            ]);

                            $shipmentsBySj->put($noSj, collect([$created]));
                            $stats['created']++;

                            continue;
                        }

                        $updatedAny = false;
                        foreach ($existing as $shipment) {
                            if ($this->fillShipmentFromPayload($shipment, $payload)) {
                                $updatedAny = true;
                            }
                        }

                        $stats[$updatedAny ? 'updated' : 'skipped']++;
                    }
                });
        });

        return $stats;
    }

    /**
     * Lengkapi kolom kosong pada daftar No SJ hasil upload CSA/TO dari Surat Jalan yang sudah tercatat lebih dulu.
     *
     * @param  list<string>  $sjNumbers
     */
    public function fillEmptyFromTrackingOrders(array $sjNumbers): int
    {
        $cleanSjs = array_values(array_unique(array_filter(array_map(fn ($sj): string => trim((string) $sj), $sjNumbers))));
        if ($cleanSjs === []) {
            return 0;
        }

        $ordersBySj = TrackingOrder::query()
            ->with('depo:id,kode,nama')
            ->whereIn('no_sj', $cleanSjs)
            ->orderByDesc('id')
            ->get()
            ->unique(fn (TrackingOrder $order): string => trim((string) $order->no_sj))
            ->keyBy(fn (TrackingOrder $order): string => trim((string) $order->no_sj));

        if ($ordersBySj->isEmpty()) {
            return 0;
        }

        $updated = 0;
        $shipments = CsaShipment::query()
            ->whereIn('no_sj', $ordersBySj->keys()->all())
            ->get();

        foreach ($shipments as $shipment) {
            $order = $ordersBySj->get(trim((string) $shipment->no_sj));
            if (! $order) {
                continue;
            }

            $payload = $this->extractPayload($order, trim((string) $shipment->no_sj));
            if ($this->fillShipmentFromPayload($shipment, $payload)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @return array{
     *     no_sj: string,
     *     tanggal_order: ?string,
     *     tanggal_kirim: ?string,
     *     kode_gudang: string,
     *     nama_gudang: ?string,
     *     target_sheet: string,
     *     has_depo: bool,
     *     tujuan_dealer: ?string,
     *     alamat_kirim: ?string,
     *     nama_kota: ?string,
     *     nama_ekspedisi: string,
     *     reff_note: ?string,
     *     total_nominal_sj: float,
     *     status_pengiriman: ?string,
     *     tanggal_diterima: ?string
     * }
     */
    private function extractPayload(TrackingOrder $order, string $noSj): array
    {
        $depo = $order->relationLoaded('depo') ? $order->depo : $order->depo()->first();
        $mapping = $depo ? $this->resolveWarehouseMapping((string) $depo->kode) : null;

        $kodeGudang = $depo && filled($depo->kode)
            ? strtoupper(trim((string) $depo->kode))
            : 'SURAT_JALAN';
        $namaGudang = $mapping?->csa_name
            ?: ($depo && filled($depo->nama) ? strtoupper(trim((string) $depo->nama)) : null);
        $targetSheet = $mapping?->target_sheet
            ?: ($depo && filled($depo->nama) ? strtoupper(trim((string) $depo->nama)) : 'SURAT JALAN');

        $alamat = $this->resolveAlamatKirim($order->alamat_dealer, $order->address);
        $namaKota = $this->extractCity($order->address, $order->alamat_dealer);

        $isDelivered = filled($order->nama_penerima) || filled($order->foto_penerima);
        $statusPengiriman = $isDelivered ? 'Sudah Diterima' : null;
        $tanggalDiterima = $isDelivered
            ? ($order->tanggal_pengiriman ?? $order->created_at)?->toDateString()
            : null;

        return [
            'no_sj' => $noSj,
            'tanggal_order' => $order->tanggal_nota?->toDateString(),
            'tanggal_kirim' => ($order->tanggal_pengiriman ?? $order->tanggal_nota ?? $order->created_at)?->toDateString(),
            'kode_gudang' => $kodeGudang,
            'nama_gudang' => $namaGudang,
            'target_sheet' => $targetSheet,
            'has_depo' => $depo !== null,
            'tujuan_dealer' => filled($order->nama_dealer) ? trim((string) $order->nama_dealer) : null,
            'alamat_kirim' => $alamat,
            'nama_kota' => $namaKota,
            'nama_ekspedisi' => 'INTERNAL',
            'reff_note' => filled($order->notes) ? trim((string) $order->notes) : null,
            'total_nominal_sj' => max(0.0, (float) ($order->jumlah_value_nota ?? 0)),
            'status_pengiriman' => $statusPengiriman,
            'tanggal_diterima' => $tanggalDiterima,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fillShipmentFromPayload(CsaShipment $shipment, array $payload): bool
    {
        foreach (['tujuan_dealer', 'alamat_kirim', 'nama_kota', 'nama_ekspedisi', 'reff_note', 'status_pengiriman'] as $textColumn) {
            if (blank($shipment->{$textColumn}) && filled($payload[$textColumn] ?? null)) {
                $shipment->{$textColumn} = $payload[$textColumn];
            }
        }

        foreach (['tanggal_order', 'tanggal_kirim', 'tanggal_diterima'] as $dateColumn) {
            if ($shipment->{$dateColumn} === null && $payload[$dateColumn] !== null) {
                $shipment->{$dateColumn} = $payload[$dateColumn];
            }
        }

        if ((float) $shipment->total_nominal_sj <= 0 && (float) $payload['total_nominal_sj'] > 0) {
            $shipment->total_nominal_sj = $payload['total_nominal_sj'];
        }

        $isPlaceholderOrigin = in_array((string) $shipment->kode_gudang, ['', 'SURAT_JALAN', 'MANUAL'], true);
        if (! empty($payload['has_depo']) && $isPlaceholderOrigin) {
            $shipment->kode_gudang = $payload['kode_gudang'];
            $shipment->nama_gudang = $payload['nama_gudang'];
            if (in_array((string) $shipment->target_sheet, ['', 'SURAT JALAN', 'MANUAL'], true)) {
                $shipment->target_sheet = $payload['target_sheet'];
            }
        }

        if (! $shipment->isDirty()) {
            return false;
        }

        $shipment->save();

        return true;
    }

    private function resolveAlamatKirim(?string $alamatDealer, ?string $address): ?string
    {
        $dealerAddr = filled($alamatDealer) ? trim((string) preg_replace('/\s+/u', ' ', (string) $alamatDealer)) : '';
        $gpsAddr = filled($address) ? trim((string) preg_replace('/\s+/u', ' ', (string) $address)) : '';

        if ($dealerAddr === '' && $gpsAddr === '') {
            return null;
        }

        if ($dealerAddr === '') {
            return $gpsAddr;
        }

        if ($gpsAddr === '') {
            return $dealerAddr;
        }

        $lowerDealer = mb_strtolower($dealerAddr);
        $lowerGps = mb_strtolower($gpsAddr);

        if (str_contains($lowerGps, $lowerDealer)) {
            return $gpsAddr;
        }

        if (str_contains($lowerDealer, $lowerGps)) {
            return $dealerAddr;
        }

        // Bila alamat dealer di nota singkat (mis. "Cisoka", "Plumbon", "BEC"), gabungkan dengan alamat lengkap GPS
        if (mb_strlen($dealerAddr) < 35 || mb_strlen($gpsAddr) > mb_strlen($dealerAddr) + 15) {
            return "{$dealerAddr} — {$gpsAddr}";
        }

        return $dealerAddr;
    }

    private function extractCity(?string ...$texts): ?string
    {
        $provinceStop = 'JAWA|SUMATERA|SUMATRA|KALIMANTAN|SULAWESI|BALI|BANTEN|LAMPUNG|RIAU|KEPULAUAN|ACEH|JAMBI|BENGKULU|MALUKU|PAPUA|NUSA|GORONTALO|DI\s|DKI\s|WEST|EAST|CENTRAL|SOUTH|NORTH|INDONESIA';

        foreach ($texts as $raw) {
            $upper = strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $raw)));
            if ($upper === '') {
                continue;
            }

            if (preg_match('/(?:,\s*|\b)(?:KABUPATEN|KAB\.?)\s+([A-Z][A-Z\s]+?)(?=,|\s+\d{5}|$|\s+(?:'.$provinceStop.'))/', $upper, $m) === 1) {
                return 'KAB. '.trim($m[1]);
            }

            if (preg_match('/(?:,\s*|\b)KOTA\s+(?:ADMINISTRASI\s+|ADM\.?\s+)?([A-Z][A-Z\s]+?)(?=,|\s+\d{5}|$|\s+(?:'.$provinceStop.'))/', $upper, $m) === 1) {
                return trim($m[1]);
            }

            if (preg_match('/(?:,\s*|\b)([A-Z][A-Z\s]{2,}?)\s+CITY(?=,|$)/', $upper, $m) === 1) {
                return trim($m[1]);
            }

            if (preg_match('/(?:,\s*|\b)([A-Z][A-Z\s]{2,}?)\s+REGENCY(?=,|$)/', $upper, $m) === 1) {
                return 'KAB. '.trim($m[1]);
            }
        }

        return null;
    }

    private function resolveWarehouseMapping(string $code): ?WarehouseMapping
    {
        $key = strtoupper(trim($code));
        if ($key === '') {
            return null;
        }

        if (! array_key_exists($key, $this->mappingCache)) {
            $this->mappingCache[$key] = WarehouseMapping::query()
                ->where('csa_code', $key)
                ->first();
        }

        return $this->mappingCache[$key];
    }
}

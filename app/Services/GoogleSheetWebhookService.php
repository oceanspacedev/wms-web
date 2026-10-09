<?php

namespace App\Services;

use App\Models\CsaShipment;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleSheetWebhookService
{
    protected string $webhookUrl;

    public function __construct(?string $webhookUrl = null)
    {
        $this->webhookUrl = $webhookUrl ?? (string) config('services.google_sheets.webhook_url', env('GOOGLE_SHEET_WEBHOOK_URL', ''));
    }

    /**
     * Set or override webhook URL.
     */
    public function setWebhookUrl(string $url): self
    {
        $this->webhookUrl = $url;

        return $this;
    }

    /**
     * Get current webhook URL.
     */
    public function getWebhookUrl(): string
    {
        return $this->webhookUrl;
    }

    /**
     * Get configured Google Spreadsheet ID.
     */
    public function getSpreadsheetId(): string
    {
        return (string) config('services.google_sheets.spreadsheet_id', env('GOOGLE_SPREADSHEET_ID', ''));
    }

    /**
     * Test webhook connectivity and push a test shipment row.
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     details: array<string, mixed>
     * }
     */
    public function testConnection(?string $targetSheet = null, bool $sendSampleRow = false): array
    {
        if (empty($this->webhookUrl)) {
            return [
                'success' => false,
                'message' => 'Google Sheet Webhook URL belum dikonfigurasi.',
                'details' => [],
            ];
        }

        try {
            // 1. Verifikasi konektivitas dengan GET ping (tanpa mengubah isi spreadsheet)
            $getPing = Http::withoutVerifying()
                ->timeout(30)
                ->get($this->webhookUrl);

            if (! $getPing->successful()) {
                return [
                    'success' => false,
                    'message' => "Uji koneksi (GET) gagal dengan status HTTP {$getPing->status()}.",
                    'details' => [
                        'http_status' => $getPing->status(),
                        'response' => $getPing->body(),
                    ],
                ];
            }

            // Jika tidak diminta mengirim baris uji, cukup kembalikan status aktif GET
            if (! $sendSampleRow || empty($targetSheet)) {
                $pingData = $getPing->json() ?? [];

                return [
                    'success' => true,
                    'message' => 'Koneksi ke Google Spreadsheet Webhook aktif dan siap menerima data!',
                    'details' => is_array($pingData) ? $pingData : ['raw' => $getPing->body()],
                ];
            }

            // 2. Jika opsi kirim baris uji aktif, kirim ke tab yang dituju
            $sampleRow = [
                now()->format('d/m/Y'),
                now()->format('d/m/Y'),
                'PT. MEDIA SELULAR INDONESIA',
                $targetSheet,
                'TOKO TEST INTEGRASI',
                'Jl. Test Integrasi No. 1',
                'JAKARTA',
                'TEST BRAND',
                'TEST-SJ-'.now()->format('YmdHis'),
                100000.0,
                'UJI KONEKSI SISTEM CSA WMS',
                1,
                1,
                1.0,
                'BEBAS BIAYA',
                'INTERNAL TEST',
                'AWB-TEST-'.rand(1000, 9999),
                0.0,
                'LUNAS',
                'TERKIRIM',
                now()->format('d/m/Y'),
                '',
                '',
                '',
                '1x UNIT TESTING',
            ];

            $postResponse = Http::withoutVerifying()
                ->timeout(60)
                ->retry(2, 1000)
                ->post($this->webhookUrl, [
                    'sheetName' => $targetSheet,
                    'rows' => [$sampleRow],
                ]);

            if ($postResponse->successful()) {
                $json = $postResponse->json() ?? [];

                return [
                    'success' => true,
                    'message' => "Koneksi berhasil! Baris pengujian berhasil dikirim ke sheet '{$targetSheet}'.",
                    'details' => is_array($json) ? $json : ['raw' => $postResponse->body()],
                ];
            }

            return [
                'success' => false,
                'message' => "Webhook POST gagal (HTTP {$postResponse->status()}).",
                'details' => [
                    'http_status' => $postResponse->status(),
                    'body' => $postResponse->body(),
                ],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Kesalahan koneksi: '.$e->getMessage(),
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Sync a collection of CsaShipment models to Google Spreadsheet.
     *
     * @param  Collection<int, CsaShipment>  $shipments
     * @param  bool  $resetHighlight  False untuk kirim ulang tanpa menghapus highlight biru data sebelumnya
     * @return array{
     *     success: bool,
     *     total: int,
     *     inserted: int,
     *     skipped: int,
     *     errors: array<int, string>
     * }
     */
    public function syncShipments(Collection $shipments, bool $resetHighlight = true): array
    {
        if (empty($this->webhookUrl)) {
            throw new Exception('Google Sheet Webhook URL belum dikonfigurasi. Silakan isi di .env (GOOGLE_SHEET_WEBHOOK_URL) atau form pengaturan.');
        }

        // Group shipments by target sheet
        $grouped = $shipments->groupBy('target_sheet');
        $totalInserted = 0;
        $totalSkipped = 0;
        $errors = [];

        foreach ($grouped as $sheetName => $items) {
            // Highlight biru data lama di sheet cukup di-reset sekali per sinkron (pada batch pertama yang
            // benar-benar menulis data), supaya semua data baru dari sinkron ini tetap biru
            $highlightReset = false;

            // Chunk rows into batches of 100 to avoid Google Apps Script timeout
            $chunks = $items->chunk(100);

            foreach ($chunks as $chunk) {
                $rows = [];
                $shipmentIds = [];

                foreach ($chunk as $shipment) {
                    $rows[] = $this->transformShipmentToRow($shipment);
                    $shipmentIds[] = $shipment->id;
                }

                try {
                    $response = Http::withoutVerifying()
                        ->timeout(120)
                        ->retry(2, 2000)
                        ->post($this->webhookUrl, [
                            'sheetName' => (string) $sheetName,
                            'rows' => $rows,
                            'resetHighlight' => $resetHighlight && ! $highlightReset,
                        ]);

                    $json = $response->json();

                    // Apps Script selalu membalas HTTP 200, termasuk saat error (misal sheet sedang sibuk)
                    if ($response->successful() && ($json['status'] ?? null) === 'success') {
                        $inserted = (int) ($json['inserted'] ?? count($rows));
                        $skipped = (int) ($json['skipped'] ?? $json['skipped_duplicate'] ?? 0);

                        $totalInserted += $inserted;
                        $totalSkipped += $skipped;

                        if ($inserted > 0) {
                            $highlightReset = true;
                        }

                        // Baris yang Nomor SJ-nya sudah ada di sheet dilewati (tidak ditimpa).
                        // Script versi lama tidak mengirim skipped_indexes, sehingga semua dianggap tertulis.
                        $skippedIds = collect($json['skipped_indexes'] ?? [])
                            ->map(fn ($index) => $shipmentIds[$index] ?? null)
                            ->filter()
                            ->values()
                            ->all();
                        $insertedIds = array_values(array_diff($shipmentIds, $skippedIds));

                        if ($insertedIds !== []) {
                            CsaShipment::whereIn('id', $insertedIds)->update([
                                'is_synced' => true,
                                'already_in_sheet' => false,
                                'synced_at' => now(),
                                'sync_error' => null,
                            ]);
                        }

                        if ($skippedIds !== []) {
                            CsaShipment::whereIn('id', $skippedIds)->update([
                                'is_synced' => true,
                                'already_in_sheet' => true,
                                'synced_at' => now(),
                                'sync_error' => null,
                            ]);
                        }
                    } else {
                        $errMsg = $response->successful()
                            ? 'Apps Script error: '.($json['message'] ?? $response->body())
                            : "HTTP {$response->status()}: {$response->body()}";
                        $errors[] = "Sheet [{$sheetName}]: {$errMsg}";

                        CsaShipment::whereIn('id', $shipmentIds)->update([
                            'sync_error' => $errMsg,
                        ]);
                    }
                } catch (Exception $e) {
                    $errMsg = $e->getMessage();
                    $errors[] = "Sheet [{$sheetName}]: {$errMsg}";

                    Log::error("GoogleSheetWebhook error on sheet {$sheetName}", [
                        'error' => $errMsg,
                    ]);

                    CsaShipment::whereIn('id', $shipmentIds)->update([
                        'sync_error' => $errMsg,
                    ]);
                }
            }
        }

        return [
            'success' => empty($errors),
            'total' => $shipments->count(),
            'inserted' => $totalInserted,
            'skipped' => $totalSkipped,
            'errors' => $errors,
        ];
    }

    /**
     * Transform CsaShipment model into 25-column Google Sheet row array.
     *
     * @return array<int, mixed>
     */
    public function transformShipmentToRow(CsaShipment $shipment): array
    {
        $tglOrder = $shipment->tanggal_order ? $shipment->tanggal_order->format('d/m/Y') : '';
        $tglKirim = $shipment->tanggal_kirim ? $shipment->tanggal_kirim->format('d/m/Y') : '';

        // Sesuaikan dengan data validation dropdown di Google Spreadsheet
        $rawBu = trim((string) ($shipment->badan_usaha ?? ''));
        $cleanBu = strtoupper(str_replace('.', '', $rawBu));
        $badanUsaha = match ($cleanBu) {
            'PT MEDIA SELULAR INDONESIA' => 'PT. MEDIA SELULAR INDONESIA',
            'CV TOP SELULAR' => 'CV. TOP SELULAR',
            'CV COMPLETE SELULAR' => 'CV. COMPLETE SELULAR',
            default => $rawBu,
        };

        // Samakan nama ekspedisi CSA dengan pilihan dropdown kolom NAMA EKSPEDISI di Google Spreadsheet
        $rawEkspedisi = trim((string) ($shipment->nama_ekspedisi ?? ''));
        $namaEkspedisi = match (strtoupper($rawEkspedisi)) {
            'J&T EXPRESS' => 'J&T',
            default => $rawEkspedisi,
        };

        return [
            $tglOrder,                                             // 1. TANGGAL ORDER
            $tglKirim,                                             // 2. TANGGAL KIRIM
            $badanUsaha,                                           // 3. BADAN USAHA
            $shipment->target_sheet ?: $shipment->nama_gudang,     // 4. DEPO [WAREHOUSE]
            $shipment->tujuan_dealer,                              // 5. TUJUAN/DEALER
            $shipment->alamat_kirim ?? '',                         // 6. ALAMAT KIRIM
            $shipment->nama_kota ?? '',                            // 7. NAMA KOTA
            $shipment->brand ?? '',                                // 8. BRAND
            $shipment->no_sj,                                      // 9. NOMOR SJ
            (float) $shipment->total_nominal_sj,                   // 10. TOTAL NOMINAL SJ
            $shipment->reff_note ?? '',                            // 11. REFFNOTE
            (int) $shipment->qty_unit,                             // 12. QTY UNIT
            (int) $shipment->qty_koli,                             // 13. QTY KOLI
            (float) $shipment->berat,                              // 14. BERAT
            $shipment->ketentuan_biaya_kirim ?? '',                // 15. KETENTUAN BIAYA KIRIM
            $namaEkspedisi,                                        // 16. NAMA EKSPEDISI
            $shipment->no_resi_awb ?? '',                          // 17. NO RESI AWB
            $shipment->biaya_kirim ? (float) $shipment->biaya_kirim : '', // 18. BIAYA KIRIM
            $shipment->status_pembayaran ?? '',                    // 19. STATUS PEMBAYARAN
            $shipment->status_pengiriman ?? '',                    // 20. STATUS PENGIRIMAN
            $shipment->tanggal_diterima ? $shipment->tanggal_diterima->format('d/m/Y') : '', // 21. TANGGAL DITERIMA
            '',                                                    // 22. LEAD TIME PROSES
            '',                                                    // 23. LEAD TIME KIRIM
            '',                                                    // 24. LEAD TIME KESELURUHAN
            $shipment->ket_isi_unit ?: (string) $shipment->qty_unit, // 25. KET. ISI UNIT
        ];
    }
}

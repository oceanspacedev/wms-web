<?php

namespace App\Jobs;

use App\Models\CsaImport;
use App\Models\CsaShipment;
use App\Services\CsaExcelParserService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessCsaImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * Timeout for parsing large Excel files (10 minutes).
     */
    public int $timeout = 600;

    /**
     * Tidak diulang otomatis; kegagalan ditandai di status import. Memproses ulang tetap aman karena
     * SJ yang sudah ada diperbarui, bukan disisipkan lagi.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public CsaImport $csaImport,
        public bool $autoSyncToSheet = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CsaExcelParserService $parser): void
    {
        $this->ensureMemoryLimit();

        $this->csaImport->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $filePath = $this->csaImport->file_path;
            if (! file_exists($filePath)) {
                $filePath = storage_path('app/'.$this->csaImport->file_path);
            }

            $result = $parser->parseAndAggregate($filePath);
            $reportType = $result['report_type'] ?? CsaImport::REPORT_SALES;

            $this->csaImport->update([
                'report_type' => $reportType,
                'period_start' => $result['period'][0] ?? null,
                'period_end' => $result['period'][1] ?? null,
                'total_raw_rows' => $result['total_raw_rows'],
                'total_shipments' => $result['total_shipments'],
                'summary_by_sheet' => $result['by_sheet'],
            ]);

            [$totalNew, $totalUpdated, $totalSkipped] = $reportType === CsaImport::REPORT_TRANSFER
                ? $this->saveTransferOrders($result['shipments'])
                : $this->saveShipments($result['shipments']);

            $this->saveItems($result['items'] ?? []);

            $this->csaImport->update([
                'status' => 'completed',
                'total_new' => $totalNew,
                'total_updated' => $totalUpdated,
                'total_skipped' => $totalSkipped,
            ]);

            // Sinkron ke Google Sheet selalu berjalan di antrean (bisa lebih dari 1 jam untuk laporan sebulan)
            if ($this->autoSyncToSheet) {
                SyncToGoogleSheetJob::dispatch($this->csaImport);
            }
        } catch (Exception $e) {
            Log::error('Error processing CSA import: '.$e->getMessage(), [
                'import_id' => $this->csaImport->id,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->csaImport->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Simpan SJ hasil ekstraksi ke Data Pengiriman. SJ yang sudah ada dari upload sebelumnya diperbarui
     * data CSA-nya tanpa menimpa isian admin, sehingga upload ulang tidak membuat data dobel.
     * SJ yang sudah ada di data hasil import spreadsheet dengan total yang sama dilewati (aturan sheet lama,
     * lihat sameAsSpreadsheet()).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: int, 1: int, 2: int} [SJ baru, SJ upload lama yang diperbarui, SJ yang sudah ada di spreadsheet]
     */
    protected function saveShipments(array $items): array
    {
        $totalNew = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;

        foreach (array_chunk($items, 500) as $chunk) {
            $sjNumbers = array_column($chunk, 'no_sj');

            $existing = CsaShipment::query()
                ->where('source', CsaShipment::SOURCE_CSA)
                ->whereIn('no_sj', $sjNumbers)
                ->get()
                ->keyBy(fn (CsaShipment $shipment): string => $this->matchKey($shipment->no_sj, $shipment->badan_usaha));

            // Total baris spreadsheet per cabang + No SJ
            $spreadsheetTotals = CsaShipment::query()
                ->toBase()
                ->where('source', CsaShipment::SOURCE_SPREADSHEET)
                ->whereIn('no_sj', $sjNumbers)
                ->get(['no_sj', 'target_sheet', 'total_nominal_sj'])
                ->groupBy(fn (object $row): string => $this->spreadsheetKey($row->target_sheet, $row->no_sj))
                ->map(fn ($rows): array => $rows->pluck('total_nominal_sj')->map(fn ($total): float => (float) $total)->all());

            $records = [];
            $now = now();

            foreach ($chunk as $item) {
                $shipment = $existing->get($this->matchKey($item['no_sj'], $item['badan_usaha']));

                if ($shipment) {
                    $this->refreshFromCsa($shipment, $item);
                    $totalUpdated++;

                    continue;
                }

                // Sudah ada di spreadsheet dengan No SJ & total sama: tidak ditambah, data spreadsheet tidak ditimpa.
                // Total beda tetap masuk sebagai baris baru dengan No SJ yang sama.
                $totals = $spreadsheetTotals->get($this->spreadsheetKey($item['target_sheet'], $item['no_sj']), []);

                if ($this->sameAsSpreadsheet($totals, (float) $item['total_nominal_sj'])) {
                    $totalSkipped++;

                    continue;
                }

                $totalNew++;
                $records[] = $this->newRecord($item, CsaShipment::SOURCE_CSA, $now);
            }

            if ($records !== []) {
                DB::table('csa_shipments')->insert($records);
            }
        }

        return [$totalNew, $totalUpdated, $totalSkipped];
    }

    /**
     * Simpan TO dari laporan realisasi TO. Nomor ISSUE unik, jadi TO yang sudah ada di Data Pengiriman (hasil import
     * spreadsheet, input manual, atau upload TO sebelumnya) tidak ditambah lagi. TO dari upload sebelumnya diperbarui
     * seperti SJ CSA; di baris lain hanya tanggal diterima yang diisi dari laporan, itu pun kalau masih kosong.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: int, 1: int, 2: int} [TO baru, TO yang diperbarui, TO yang sudah ada]
     */
    protected function saveTransferOrders(array $items): array
    {
        $totalNew = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;

        foreach (array_chunk($items, 500) as $chunk) {
            $existing = CsaShipment::query()
                ->whereIn('no_sj', array_column($chunk, 'no_sj'))
                ->get()
                ->groupBy('no_sj');

            $records = [];
            $now = now();

            foreach ($chunk as $item) {
                $rows = $existing->get($item['no_sj']);

                if ($rows === null) {
                    $totalNew++;
                    $records[] = $this->newRecord($item, CsaShipment::SOURCE_TO, $now);

                    continue;
                }

                $ownRow = $rows->firstWhere('source', CsaShipment::SOURCE_TO);

                if ($ownRow) {
                    $this->refreshFromCsa($ownRow, $item);
                    $totalUpdated++;

                    continue;
                }

                $receivedFilled = $item['tanggal_diterima'] !== null
                    ? $rows->whereNull('tanggal_diterima')
                        ->each(function (CsaShipment $shipment) use ($item): void {
                            $shipment->update(['tanggal_diterima' => $item['tanggal_diterima']]);
                        })
                        ->count()
                    : 0;

                $receivedFilled > 0 ? $totalUpdated++ : $totalSkipped++;
            }

            if ($records !== []) {
                DB::table('csa_shipments')->insert($records);
            }
        }

        return [$totalNew, $totalUpdated, $totalSkipped];
    }

    /**
     * Ringkasan penjualan per gudang per barang milik upload ini; proses ulang menggantinya, bukan menambah.
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected function saveItems(array $items): void
    {
        $this->csaImport->items()->delete();

        foreach (array_chunk($items, 500) as $chunk) {
            DB::table('csa_import_items')->insert(array_map(
                fn (array $item): array => ['csa_import_id' => $this->csaImport->id] + $item,
                $chunk,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function newRecord(array $item, string $source, mixed $now): array
    {
        return [
            'csa_import_id' => $this->csaImport->id,
            'source' => $source,
            'no_sj' => $item['no_sj'],
            'no_trans' => $item['no_trans'],
            'no_so' => $item['no_so'],
            'tanggal_order' => $item['tanggal_order'],
            'tanggal_kirim' => $item['tanggal_kirim'],
            'tanggal_diterima' => $item['tanggal_diterima'] ?? null,
            'badan_usaha' => $item['badan_usaha'],
            'kode_gudang' => $item['kode_gudang'],
            'nama_gudang' => $item['nama_gudang'],
            'target_sheet' => $item['target_sheet'],
            'tujuan_dealer' => $item['tujuan_dealer'],
            'alamat_kirim' => $item['alamat_kirim'],
            'nama_kota' => $item['nama_kota'],
            'brand' => $item['brand'],
            'reff_note' => $item['reff_note'],
            'total_nominal_sj' => $item['total_nominal_sj'],
            'qty_unit' => $item['qty_unit'],
            'qty_koli' => $item['qty_koli'],
            'berat' => $item['berat'],
            'ketentuan_biaya_kirim' => $item['ketentuan_biaya_kirim'],
            'nama_ekspedisi' => $item['nama_ekspedisi'],
            'no_resi_awb' => $item['no_resi_awb'],
            'biaya_kirim' => $item['biaya_kirim'],
            'status_pembayaran' => $item['status_pembayaran'],
            'status_pengiriman' => $item['status_pengiriman'],
            'ket_isi_unit' => $item['ket_isi_unit'],
            'is_synced' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    protected function spreadsheetKey(?string $cabang, ?string $noSj): string
    {
        return mb_strtoupper(trim((string) $cabang)).'|'.trim((string) $noSj);
    }

    /**
     * SJ CSA dianggap sama dengan baris spreadsheet (cabang + No SJ sama) kalau totalnya sama sampai rupiah,
     * sama setelah ditambah PPN 11% (admin cabang mengetik nominal termasuk PPN; toleransi 0,5% untuk
     * pembulatan), atau total di spreadsheet dikosongkan.
     *
     * @param  list<float>  $spreadsheetTotals
     */
    protected function sameAsSpreadsheet(array $spreadsheetTotals, float $csaTotal): bool
    {
        $withPpn = $csaTotal * 1.11;

        foreach ($spreadsheetTotals as $total) {
            if ($total === 0.0
                || round($total) === round($csaTotal)
                || abs($total - $withPpn) <= max(2, $withPpn * 0.005)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Perbarui SJ yang sudah ada dengan data laporan CSA terbaru. Isian admin (ekspedisi, resi, status,
     * tanggal diterima, dll.) tidak pernah ditimpa; hanya diisi dari laporan kalau masih kosong.
     *
     * @param  array<string, mixed>  $item
     */
    protected function refreshFromCsa(CsaShipment $shipment, array $item): void
    {
        $shipment->csa_import_id = $this->csaImport->id;

        foreach (CsaShipment::CSA_FIELDS as $field) {
            $shipment->{$field} = $item[$field] ?? null;
        }

        foreach (CsaShipment::FILL_IF_EMPTY_FIELDS as $field) {
            $current = $shipment->{$field};
            $isEmpty = blank($current) || (in_array($field, ['qty_koli', 'berat'], true) && (float) $current === 0.0);

            if ($isEmpty && filled($item[$field] ?? null)) {
                $shipment->{$field} = $item[$field];
            }
        }

        $shipment->save();
    }

    /**
     * Kunci pencocokan SJ antar upload: Nomor SJ + badan usaha (nomor SJ bisa sama di badan usaha lain).
     */
    protected function matchKey(?string $noSj, ?string $badanUsaha): string
    {
        $bu = preg_replace('/\s+/', ' ', str_replace('.', '', mb_strtoupper(trim((string) $badanUsaha))));

        return trim((string) $noSj).'|'.$bu;
    }

    /**
     * Dipanggil worker jika job mati di luar try/catch (timeout, kehabisan memori),
     * supaya status import tidak tertahan di "Sedang Diproses".
     */
    public function failed(?Throwable $exception): void
    {
        $this->csaImport->update([
            'status' => 'failed',
            'error_message' => 'Proses ekstraksi terhenti: '.($exception?->getMessage() ?: 'job antrean gagal'),
        ]);
    }

    /**
     * Laporan sebulan (±17 MB) butuh ±250 MB saat diekstrak, sedangkan memory_limit
     * PHP CLI di server sering hanya 128 MB.
     */
    protected function ensureMemoryLimit(): void
    {
        $limit = (string) ini_get('memory_limit');

        if ($limit === '-1') {
            return;
        }

        $bytes = (int) $limit * match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($bytes < 1024 ** 3) {
            ini_set('memory_limit', '1024M');
        }
    }
}

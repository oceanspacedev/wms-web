<?php

namespace App\Jobs;

use App\Models\CsaImport;
use App\Services\CsaExcelParserService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessCsaImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * Timeout for parsing large Excel files (10 minutes).
     */
    public int $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public CsaImport $csaImport,
        public bool $autoSyncToSheet = false,
        public bool $syncImmediately = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CsaExcelParserService $parser): void
    {
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

            $this->csaImport->update([
                'total_raw_rows' => $result['total_raw_rows'],
                'total_shipments' => $result['total_shipments'],
                'summary_by_sheet' => $result['by_sheet'],
            ]);

            // Save shipments into database in chunks of 500
            $chunkSize = 500;
            $chunks = array_chunk($result['shipments'], $chunkSize);

            foreach ($chunks as $chunk) {
                $records = [];
                $now = now();

                foreach ($chunk as $item) {
                    $records[] = [
                        'csa_import_id' => $this->csaImport->id,
                        'no_sj' => $item['no_sj'],
                        'no_trans' => $item['no_trans'],
                        'no_so' => $item['no_so'],
                        'tanggal_order' => $item['tanggal_order'],
                        'tanggal_kirim' => $item['tanggal_kirim'],
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

                DB::table('csa_shipments')->insert($records);
            }

            $this->csaImport->update([
                'status' => 'completed',
            ]);

            if ($this->autoSyncToSheet) {
                if ($this->syncImmediately) {
                    SyncToGoogleSheetJob::dispatchSync($this->csaImport);
                } else {
                    SyncToGoogleSheetJob::dispatch($this->csaImport);
                }
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
}

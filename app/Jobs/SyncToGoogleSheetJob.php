<?php

namespace App\Jobs;

use App\Models\CsaImport;
use App\Models\CsaShipment;
use App\Services\GoogleSheetWebhookService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncToGoogleSheetJob implements ShouldQueue
{
    use Queueable;

    /**
     * Jumlah SJ yang dikirim per job (±10 request ke Apps Script, beberapa menit).
     * Sisanya dilanjutkan job berikutnya, karena laporan sebulan bisa butuh lebih dari 1 jam.
     */
    public const BATCH_SIZE = 1000;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    public int $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param  array<int, string>  $resetSheets  Sheet yang highlight birunya sudah di-reset pada sinkron ini
     */
    public function __construct(
        public CsaImport $csaImport,
        public ?string $targetSheet = null,
        public ?string $overrideWebhookUrl = null,
        public bool $resetHighlight = true,
        public array $resetSheets = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GoogleSheetWebhookService $webhookService): void
    {
        if ($this->overrideWebhookUrl) {
            $webhookService->setWebhookUrl($this->overrideWebhookUrl);
        }

        // SJ yang gagal dikirim ditaruh paling belakang agar tidak menghalangi SJ lain,
        // lalu urut per sheet supaya setiap request berisi 100 baris dari sheet yang sama
        $shipments = $this->pendingShipments()
            ->orderByRaw('sync_error is not null')
            ->orderBy('target_sheet')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($shipments->isEmpty()) {
            return;
        }

        $inserted = 0;
        $skipped = 0;
        $errors = [];

        try {
            foreach ($shipments->groupBy('target_sheet') as $sheetName => $items) {
                $sheetName = (string) $sheetName;
                $result = $webhookService->syncShipments(
                    $items,
                    $this->resetHighlight && ! in_array($sheetName, $this->resetSheets, true)
                );

                // Highlight sheet ini sudah di-reset oleh batch yang menulis data; batch berikutnya tidak perlu lagi
                if ($result['inserted'] > 0 && ! in_array($sheetName, $this->resetSheets, true)) {
                    $this->resetSheets[] = $sheetName;
                }

                $inserted += $result['inserted'];
                $skipped += $result['skipped'];
                $errors = [...$errors, ...$result['errors']];
            }
        } catch (Exception $e) {
            Log::error("Error syncing import #{$this->csaImport->id} to Google Sheet: {$e->getMessage()}");
            throw $e;
        }

        // Update total synced count on parent import
        $syncedCount = $this->csaImport->shipments()->where('is_synced', true)->count();
        $this->csaImport->update([
            'total_synced' => $syncedCount,
        ]);

        $remaining = $this->pendingShipments()->count();

        Log::info("Google Sheet Sync completed for Import #{$this->csaImport->id}", [
            'target_sheet' => $this->targetSheet ?? 'ALL',
            'inserted' => $inserted,
            'skipped' => $skipped,
            'remaining' => $remaining,
            'errors' => $errors,
        ]);

        // Lanjutkan sisa SJ di job berikutnya selama batch ini ada yang berhasil terkirim;
        // jika tidak ada sama sekali (misal webhook ditolak), berhenti agar tidak berulang tanpa akhir
        if ($remaining > 0 && $inserted + $skipped > 0) {
            static::dispatch(
                $this->csaImport,
                $this->targetSheet,
                $this->overrideWebhookUrl,
                $this->resetHighlight,
                $this->resetSheets
            );
        }
    }

    /**
     * @return HasMany<CsaShipment, CsaImport>
     */
    protected function pendingShipments(): HasMany
    {
        return $this->csaImport->shipments()
            ->where('is_synced', false)
            ->when($this->targetSheet, fn ($query) => $query->where('target_sheet', $this->targetSheet));
    }
}

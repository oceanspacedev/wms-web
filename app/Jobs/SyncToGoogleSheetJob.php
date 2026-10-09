<?php

namespace App\Jobs;

use App\Models\CsaImport;
use App\Services\GoogleSheetWebhookService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncToGoogleSheetJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public CsaImport $csaImport,
        public ?string $targetSheet = null,
        public ?string $overrideWebhookUrl = null,
        public bool $resetHighlight = true
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GoogleSheetWebhookService $webhookService): void
    {
        if ($this->overrideWebhookUrl) {
            $webhookService->setWebhookUrl($this->overrideWebhookUrl);
        }

        $query = $this->csaImport->shipments()->where('is_synced', false);

        if ($this->targetSheet) {
            $query->where('target_sheet', $this->targetSheet);
        }

        $shipments = $query->get();

        if ($shipments->isEmpty()) {
            return;
        }

        try {
            $result = $webhookService->syncShipments($shipments, $this->resetHighlight);

            // Update total synced count on parent import
            $syncedCount = $this->csaImport->shipments()->where('is_synced', true)->count();
            $this->csaImport->update([
                'total_synced' => $syncedCount,
            ]);

            Log::info("Google Sheet Sync completed for Import #{$this->csaImport->id}", [
                'target_sheet' => $this->targetSheet ?? 'ALL',
                'inserted' => $result['inserted'],
                'skipped' => $result['skipped'],
                'errors' => $result['errors'],
            ]);
        } catch (Exception $e) {
            Log::error("Error syncing import #{$this->csaImport->id} to Google Sheet: {$e->getMessage()}");
            throw $e;
        }
    }
}

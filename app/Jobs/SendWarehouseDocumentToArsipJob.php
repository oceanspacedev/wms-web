<?php

namespace App\Jobs;

use App\Models\WarehouseDocument;
use App\Services\ScmArsipClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendWarehouseDocumentToArsipJob implements ShouldQueue
{
    use Queueable;

    /**
     * Dicoba ulang otomatis selama SCM Arsip tidak bisa dihubungi.
     */
    public int $tries = 5;

    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(public WarehouseDocument $document) {}

    /**
     * Jeda antar percobaan: 1 menit, 5 menit, 15 menit, lalu 1 jam.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    /**
     * Execute the job.
     */
    public function handle(ScmArsipClient $client): void
    {
        if ($this->document->status === WarehouseDocument::STATUS_SENT) {
            return;
        }

        if (! $client->isConfigured()) {
            $this->markFailed('SCM Arsip belum dikonfigurasi (SCM_ARSIP_URL dan SCM_ARSIP_TOKEN).');

            return;
        }

        if (! Storage::disk('local')->exists($this->document->file_path)) {
            $this->markFailed('File dokumen tidak ditemukan di WMS.');

            return;
        }

        try {
            $result = $client->sendDocument($this->document);
        } catch (RequestException $e) {
            // 4xx (PO tidak cocok, file ditolak, token salah) tidak akan berhasil walau diulang
            if ($e->response->clientError() && $e->response->status() !== 429) {
                $this->markFailed($e->response->json('message') ?: "SCM Arsip menolak dokumen (HTTP {$e->response->status()}).");

                return;
            }

            $this->document->update(['error_message' => "SCM Arsip error (HTTP {$e->response->status()}), akan dicoba ulang otomatis."]);

            throw $e;
        } catch (ConnectionException $e) {
            $this->document->update(['error_message' => 'SCM Arsip tidak bisa dihubungi, akan dicoba ulang otomatis.']);

            throw $e;
        }

        $this->document->update([
            'status' => WarehouseDocument::STATUS_SENT,
            'arsip_document_id' => $result['document_id'] ?? null,
            'error_message' => null,
            'sent_at' => now(),
        ]);
    }

    /**
     * Semua percobaan habis (atau job mati karena timeout).
     */
    public function failed(?Throwable $exception): void
    {
        $this->markFailed('Gagal dikirim ke SCM Arsip: '.($exception?->getMessage() ?: 'job antrean gagal'));
    }

    private function markFailed(string $message): void
    {
        $this->document->update([
            'status' => WarehouseDocument::STATUS_FAILED,
            'error_message' => $message,
        ]);
    }
}

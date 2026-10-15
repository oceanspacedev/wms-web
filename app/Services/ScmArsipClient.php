<?php

namespace App\Services;

use App\Models\WarehouseDocument;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Klien HTTP ke endpoint integrasi WMS di SCM Arsip. Path `/api/integrations/whms` dan
 * field `whms_document_id` adalah kontrak milik SCM Arsip, jadi namanya tetap.
 */
class ScmArsipClient
{
    private string $baseUrl;

    private string $token;

    public function __construct(?string $baseUrl = null, ?string $token = null)
    {
        $this->baseUrl = (string) ($baseUrl ?? config('services.scm_arsip.url'));
        $this->token = (string) ($token ?? config('services.scm_arsip.token'));
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * Program (PO/SJ) di SCM Arsip untuk satu kode gudang, atau semua gudang jika kode tidak diisi.
     * Yang belum punya Memo/DO ada di urutan atas.
     *
     * @return array<int, array{id: string, title: string, po_sj_number: ?string, kode_gudang: string, has_memo: bool}>
     */
    public function programs(?string $kodeGudang = null): array
    {
        return $this->request()
            ->get('/api/integrations/whms/programs', filled($kodeGudang) ? ['kode_gudang' => $kodeGudang] : [])
            ->throw()
            ->json('programs', []);
    }

    /**
     * Kirim file Memo/DO ke program di SCM Arsip.
     *
     * @return array{document_id?: string, program_id?: string}
     */
    public function sendDocument(WarehouseDocument $document): array
    {
        $stream = Storage::disk('local')->readStream($document->file_path);

        if ($stream === null) {
            throw new RuntimeException("File {$document->file_path} tidak ditemukan di WMS.");
        }

        return $this->request()
            ->attach('file', $stream, $document->original_name)
            ->post('/api/integrations/whms/documents', [
                'program_id' => $document->arsip_program_id,
                'kode_gudang' => $document->kode_gudang,
                'whms_document_id' => $document->uuid,
                'uploaded_by' => (string) $document->user?->name,
            ])
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('SCM Arsip belum dikonfigurasi (SCM_ARSIP_URL dan SCM_ARSIP_TOKEN).');
        }

        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(60);
    }
}

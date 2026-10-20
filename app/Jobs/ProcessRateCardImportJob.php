<?php

namespace App\Jobs;

use App\Filament\Resources\ExpeditionRateCards\ExpeditionRateCardResource;
use App\Models\RateCardImport;
use App\Services\RateCardImportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Proses file pricelist Tarif Kontrak (±200 ribu baris) di antrean; lewat request web akan kena 504 Gateway Time-out.
 * Status & jumlah tarif dicatat di Riwayat Import halaman Tarif Kontrak, lalu pengunggah dikabari lewat notifikasi lonceng.
 */
class ProcessRateCardImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /**
     * Tidak diulang otomatis. Import berjalan dalam satu transaksi, jadi kalau gagal tarif lama tetap utuh.
     */
    public int $tries = 1;

    public function __construct(public RateCardImport $import) {}

    public function handle(RateCardImportService $importer): void
    {
        $this->import->update(['status' => 'processing', 'error_message' => null, 'started_at' => now(), 'finished_at' => null]);

        $result = $importer->importFile(
            filePath: Storage::disk('local')->path($this->import->file_path),
            mode: $this->import->mode,
            defaultInsurancePercent: $this->import->default_insurance_percent,
        );

        $this->import->update([
            'status' => 'completed',
            'total_rows' => $result['total_rows'],
            'total_unique' => $result['unique_records_count'],
            'total_created' => $result['imported_count'],
            'total_updated' => $result['updated_count'],
            'total_new_expeditions' => $result['new_expeditions_count'],
            'finished_at' => now(),
        ]);

        // File besar tidak perlu disimpan lagi setelah berhasil; yang gagal disimpan untuk Proses Ulang
        Storage::disk('local')->delete($this->import->file_path);

        $this->notify(Notification::make()
            ->success()
            ->title('Import tarif selesai')
            ->body(e('File '.$this->import->file_name.'. '.$result['message']))
            ->actions([
                Action::make('lihat')
                    ->label('Lihat Tarif Kontrak')
                    ->url(ExpeditionRateCardResource::getUrl('index', panel: 'admin'))
                    ->markAsRead(),
            ]));
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage() ?: 'Proses antrean terhenti';

        Log::error('Import tarif ekspedisi gagal: '.$message, [
            'rate_card_import_id' => $this->import->id,
            'file_name' => $this->import->file_name,
        ]);

        $this->import->update([
            'status' => 'failed',
            'error_message' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
        ]);

        $this->notify(Notification::make()
            ->danger()
            ->title('Import tarif gagal')
            ->body(e('File '.$this->import->file_name.': '.$message.'. Tarif lama tidak berubah.'))
            ->actions([
                Action::make('lihat')
                    ->label('Lihat Riwayat Import')
                    ->url(ExpeditionRateCardResource::getUrl('index', panel: 'admin'))
                    ->markAsRead(),
            ]));
    }

    private function notify(Notification $notification): void
    {
        // Langsung ditulis ke tabel notifikasi, tidak perlu antre lagi
        $this->import->user?->notifyNow($notification->toDatabase());
    }
}

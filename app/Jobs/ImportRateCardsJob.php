<?php

namespace App\Jobs;

use App\Filament\Resources\ExpeditionRateCards\ExpeditionRateCardResource;
use App\Models\User;
use App\Services\RateCardImportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Import file pricelist tarif ekspedisi (±200 ribu baris) di antrean; lewat request web akan kena 504 Gateway Time-out.
 * Hasilnya dikabarkan ke pengunggah lewat notifikasi lonceng.
 */
class ImportRateCardsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /**
     * Tidak diulang otomatis. Import berjalan dalam satu transaksi, jadi kalau gagal tarif lama tetap utuh.
     */
    public int $tries = 1;

    /**
     * @param  string  $path  path file di disk local
     */
    public function __construct(
        public string $path,
        public string $fileName,
        public string $mode,
        public float $defaultInsurancePercent,
        public ?int $userId,
    ) {}

    public function handle(RateCardImportService $importer): void
    {
        $result = $importer->importFile(
            filePath: Storage::disk('local')->path($this->path),
            mode: $this->mode,
            defaultInsurancePercent: $this->defaultInsurancePercent,
        );

        Storage::disk('local')->delete($this->path);

        $this->notify(Notification::make()
            ->success()
            ->title('Import tarif selesai')
            ->body(e('File '.$this->fileName.'. '.$result['message']))
            ->actions([
                Action::make('lihat')
                    ->label('Lihat Tarif Kontrak')
                    ->url(ExpeditionRateCardResource::getUrl('index', panel: 'admin'))
                    ->markAsRead(),
            ]));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Import tarif ekspedisi gagal: '.$exception?->getMessage(), [
            'file' => $this->path,
            'file_name' => $this->fileName,
        ]);

        $this->notify(Notification::make()
            ->danger()
            ->title('Import tarif gagal')
            ->body(e('File '.$this->fileName.': '.($exception?->getMessage() ?: 'proses antrean terhenti').'. Tarif lama tidak berubah.')));
    }

    private function notify(Notification $notification): void
    {
        $user = $this->userId ? User::find($this->userId) : null;

        // Langsung ditulis ke tabel notifikasi, tidak perlu antre lagi
        $user?->notifyNow($notification->toDatabase());
    }
}

<?php

namespace App\Jobs;

use App\Filament\Resources\CsaShipments\CsaShipmentResource;
use App\Models\User;
use App\Services\ShipmentSpreadsheetImporter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Import file spreadsheet "Update Pengiriman" ke Data Pengiriman di antrean (puluhan ribu baris butuh 1–2 menit),
 * lalu kabari pengunggah lewat notifikasi lonceng.
 */
class ImportShipmentSpreadsheetJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /**
     * Tidak diulang otomatis. Import berjalan dalam satu transaksi, jadi kalau gagal tidak ada data yang berubah.
     */
    public int $tries = 1;

    /**
     * @param  string  $path  path file di disk local
     */
    public function __construct(
        public string $path,
        public string $fileName,
        public bool $replaceExisting,
        public ?int $userId,
    ) {}

    public function handle(ShipmentSpreadsheetImporter $importer): void
    {
        // File ±90 ribu baris butuh ±70 MB; memory_limit PHP CLI di server sering hanya 128 MB
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1' && ini_parse_quantity($limit) < 512 * 1024 ** 2) {
            ini_set('memory_limit', '512M');
        }

        $result = $importer->import(Storage::disk('local')->path($this->path), $this->replaceExisting);

        Storage::disk('local')->delete($this->path);

        $this->notify($this->summaryNotification($result));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Import spreadsheet Data Pengiriman gagal: '.$exception?->getMessage(), [
            'file' => $this->path,
            'file_name' => $this->fileName,
        ]);

        $this->notify(Notification::make()
            ->danger()
            ->title('Import spreadsheet gagal')
            ->body('File '.$this->fileName.': '.($exception?->getMessage() ?: 'proses antrean terhenti').'. Tidak ada data yang berubah.'));
    }

    /**
     * @param  array{tabs: array<string, array{imported: int, replaced: int}>, skipped: list<string>, unreadable: int}  $result
     */
    private function summaryNotification(array $result): Notification
    {
        if ($result['tabs'] === []) {
            return Notification::make()
                ->warning()
                ->title('Tidak ada tab Data Pengiriman di file ini')
                ->body('File '.$this->fileName.' tidak berisi tab dengan header TUJUAN/DEALER di kolom E dan NOMOR SJ di kolom I. Tidak ada data yang berubah.');
        }

        $total = array_sum(array_column($result['tabs'], 'imported'));
        $replaced = array_sum(array_column($result['tabs'], 'replaced'));

        $lines = [
            'File '.$this->fileName.'.',
            collect($result['tabs'])
                ->map(fn (array $tab, string $name): string => $name.' '.$this->number($tab['imported']))
                ->implode(' · '),
        ];

        if ($replaced > 0) {
            $lines[] = $this->number($replaced).' baris hasil import sebelumnya diganti.';
        }

        if ($result['skipped'] !== []) {
            $lines[] = 'Tab dilewati (bukan tabel pengiriman): '.implode(', ', $result['skipped']).'.';
        }

        if ($result['unreadable'] > 0) {
            $lines[] = $this->number($result['unreadable']).' isian teks di kolom angka/tanggal dikosongkan (mis. "BELUM TAU" di TANGGAL DITERIMA).';
        }

        return Notification::make()
            ->success()
            ->title('Import spreadsheet selesai: '.$this->number($total).' baris')
            ->body(implode('<br>', array_map('e', $lines)))
            ->actions([
                Action::make('lihat')
                    ->label('Lihat Data Pengiriman')
                    ->url(CsaShipmentResource::getUrl('index', panel: 'admin'))
                    ->markAsRead(),
            ]);
    }

    private function notify(Notification $notification): void
    {
        $user = $this->userId ? User::find($this->userId) : null;

        // Langsung ditulis ke tabel notifikasi, tidak perlu antre lagi
        $user?->notifyNow($notification->toDatabase());
    }

    private function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}

<?php

namespace App\Jobs;

use App\Models\TrackingOrder;
use App\Services\TrackingOrderExporter;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Models\Export;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\File;
use Illuminate\Support\Number;
use Throwable;

/**
 * Buat file Excel Surat Jalan (dengan foto) di belakang layar, simpan di folder export Filament, lalu kirim
 * notifikasi lonceng berisi tombol Download seperti export Filament lainnya.
 */
class ExportTrackingOrdersJob implements ShouldQueue
{
    use Queueable;

    /** Membaca & memperkecil ratusan foto bisa beberapa menit. */
    public int $timeout = 1200;

    public int $tries = 1;

    /**
     * @param  list<int>  $orderIds  urutan sesuai tabel saat tombol Export diklik
     */
    public function __construct(
        public Export $export,
        public array $orderIds,
    ) {}

    public function handle(TrackingOrderExporter $exporter): void
    {
        $orders = TrackingOrder::query()->with('depo')->whereKey($this->orderIds)->get()->keyBy('id');
        $ordered = collect($this->orderIds)->map(fn (int $id): ?TrackingOrder => $orders->get($id))->filter();

        $file = tempnam(sys_get_temp_dir(), 'surat-jalan').'.xlsx';

        try {
            $count = $exporter->write($ordered, $file);

            $this->export->getFileDisk()->putFileAs($this->export->getFileDirectory(), new File($file), $this->export->file_name.'.xlsx');
        } finally {
            @unlink($file);
        }

        $this->export->forceFill([
            'processed_rows' => $count,
            'successful_rows' => $count,
            'completed_at' => now(),
        ])->save();

        Notification::make()
            ->title('Export Surat Jalan selesai')
            ->body(Number::format($count).' surat jalan berhasil diekspor, lengkap dengan foto nota & penerima.')
            ->success()
            ->actions([ExportFormat::Xlsx->getDownloadNotificationAction($this->export, 'web')])
            ->sendToDatabase($this->export->user, isEventDispatched: true);
    }

    public function failed(?Throwable $exception): void
    {
        Notification::make()
            ->title('Export Surat Jalan gagal')
            ->body($exception?->getMessage() ?: 'Coba export lagi.')
            ->danger()
            ->sendToDatabase($this->export->user, isEventDispatched: true);
    }
}

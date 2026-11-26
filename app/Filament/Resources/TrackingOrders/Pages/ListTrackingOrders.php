<?php

namespace App\Filament\Resources\TrackingOrders\Pages;

use App\Filament\Resources\TrackingOrders\TrackingOrderResource;
use App\Jobs\ExportTrackingOrdersJob;
use App\Models\CsaShipment;
use App\Services\TrackingOrderExporter;
use App\Services\TrackingOrderShipmentSyncService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListTrackingOrders extends ListRecords
{
    protected static string $resource = TrackingOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Mengikuti filter, pencarian & urutan tabel yang sedang dipakai; foto membuat prosesnya di belakang layar
            Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->modalHeading('Export Surat Jalan')
                ->modalDescription(fn (): string => number_format($this->getFilteredTableQuery()->count(), 0, ',', '.').' surat jalan sesuai filter tabel akan diekspor beserta foto nota & penerima. File diproses di belakang layar; tombol Download muncul di notifikasi lonceng.')
                ->modalWidth(Width::Medium)
                ->modalSubmitActionLabel('Export')
                ->action(function (): void {
                    $ids = $this->getFilteredSortedTableQuery()->pluck('tracking_orders.id')->map(fn ($id): int => (int) $id)->all();

                    if ($ids === []) {
                        Notification::make()->title('Tidak ada surat jalan untuk diekspor')->warning()->send();

                        return;
                    }

                    $export = new Export;
                    $export->forceFill([
                        'user_id' => auth()->id(),
                        'exporter' => TrackingOrderExporter::class,
                        'total_rows' => count($ids),
                        // File berisi foto penerima: jangan di disk publik
                        'file_disk' => config('filament.default_filesystem_disk') === 'public' ? 'local' : config('filament.default_filesystem_disk'),
                        'file_name' => 'surat-jalan-'.now()->format('Y-m-d-His'),
                    ])->save();

                    ExportTrackingOrdersJob::dispatch($export, $ids);

                    Notification::make()
                        ->title('Export dimulai')
                        ->body('Tombol Download muncul di notifikasi lonceng setelah file selesai dibuat.')
                        ->success()
                        ->send();
                }),

            Action::make('syncToDataPengiriman')
                ->label('Sinkron ke Data Pengiriman')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->can('create', CsaShipment::class) ?? false)
                    || (auth()->user()?->can('update', new CsaShipment) ?? false))
                ->requiresConfirmation()
                ->modalHeading('Sinkronkan Surat Jalan ke Data Pengiriman')
                ->modalDescription('Menyinkronkan seluruh data Surat Jalan (berdasarkan Nomor SJ) ke tabel Data Pengiriman. Data CSA atau isian admin yang sudah ada tidak akan ditimpa.')
                ->modalSubmitActionLabel('Sinkron Sekarang')
                ->action(function (): void {
                    $stats = app(TrackingOrderShipmentSyncService::class)->syncAll(auth()->user()?->cabangScope());

                    Notification::make()
                        ->title('Sinkronisasi ke Data Pengiriman selesai')
                        ->body(number_format($stats['created'], 0, ',', '.').' data baru ditambahkan · '.number_format($stats['updated'], 0, ',', '.').' baris dilengkapi · '.number_format($stats['skipped'], 0, ',', '.').' sudah sesuai.')
                        ->success()
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}

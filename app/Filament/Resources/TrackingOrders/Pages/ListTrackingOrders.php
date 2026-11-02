<?php

namespace App\Filament\Resources\TrackingOrders\Pages;

use App\Filament\Resources\TrackingOrders\TrackingOrderResource;
use App\Jobs\ExportTrackingOrdersJob;
use App\Services\TrackingOrderExporter;
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

            CreateAction::make(),
        ];
    }
}

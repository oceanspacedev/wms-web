<?php

namespace App\Filament\Resources\StockAllocations\Pages;

use App\Filament\Resources\StockAllocations\StockAllocationResource;
use App\Models\StockAllocation;
use App\Services\StockAllocationService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Storage;

class ListStockAllocations extends ListRecords
{
    protected static string $resource = StockAllocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload Data Stok')
                ->visible(fn (): bool => auth()->user()?->can('create', StockAllocation::class) ?? false)
                ->modalHeading('Hitung Alokasi Stok')
                ->modalDescription('Upload file DOS (tab "Data": GUDANG, REGION, KETERANGAN, QTY, KODE_SKU).')
                ->modalWidth(Width::Large)
                ->modalSubmitActionLabel('Hitung')
                ->schema([
                    FileUpload::make('file')
                        ->label('File stok & penjualan (.xlsx)')
                        ->disk('local')
                        ->directory('stock-allocation-imports')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/octet-stream',
                            'application/zip',
                        ])
                        ->maxSize(51200)
                        ->storeFileNamesIn('file_name')
                        ->required(),
                    Grid::make(2)->schema([
                        TextInput::make('target_dos')
                            ->label('Target DOS')
                            ->numeric()
                            ->integer()
                            ->minValue(14)
                            ->maxValue(120)
                            ->suffix('hari')
                            ->default(30)
                            ->required(),
                        TextInput::make('min_transfer_qty')
                            ->label('Transfer minimal')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->suffix('unit')
                            ->default(2)
                            ->required(),
                    ]),
                ])
                // File DOS berisi ratusan baris, jadi dihitung langsung (±1 detik) tanpa antrean
                ->action(function (array $data): void {
                    $path = Storage::disk('local')->path($data['file']);
                    $fileName = $data['file_name'] ?? basename($data['file']);

                    try {
                        $allocation = app(StockAllocationService::class)->import(
                            $path,
                            pathinfo($fileName, PATHINFO_FILENAME),
                            $fileName,
                            (int) $data['target_dos'],
                            (int) $data['min_transfer_qty'],
                            auth()->id(),
                        );
                    } catch (Exception $exception) {
                        Notification::make()
                            ->title('File tidak bisa dibaca')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }

                    Notification::make()
                        ->title('Alokasi selesai dihitung')
                        ->body($allocation->transfer_count.' saran transfer ('.StockAllocationResource::units($allocation->transfer_qty).')')
                        ->success()
                        ->send();

                    $this->redirect(StockAllocationResource::getUrl('view', ['record' => $allocation]));
                }),
        ];
    }
}

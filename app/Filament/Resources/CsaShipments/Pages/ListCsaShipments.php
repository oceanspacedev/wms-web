<?php

namespace App\Filament\Resources\CsaShipments\Pages;

use App\Filament\Resources\CsaShipments\CsaShipmentResource;
use App\Jobs\ImportShipmentSpreadsheetJob;
use App\Models\CsaShipment;
use App\Services\DataPengirimanExporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListCsaShipments extends ManageRecords
{
    protected static string $resource = CsaShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export Excel')
                ->color('gray')
                ->action(fn (): StreamedResponse => app(DataPengirimanExporter::class)->download(
                    $this->getFilteredSortedTableQuery(),
                    'Data Pengiriman '.$this->selectedCabang().' '.now()->format('Y-m-d').'.xlsx',
                )),

            Action::make('importSpreadsheet')
                ->label('Import Spreadsheet')
                ->color('gray')
                // Mengisi semua cabang sekaligus, jadi hanya untuk admin pusat
                ->visible(fn (): bool => blank(auth()->user()?->cabangScope())
                    && (auth()->user()?->can('create', CsaShipment::class) ?? false))
                ->modalHeading('Import Spreadsheet Update Pengiriman')
                ->modalDescription('Download Google Sheet lewat File → Download → Microsoft Excel (.xlsx), lalu upload di sini. Setiap tab menjadi cabang dengan kolom yang sama seperti tabel ini.')
                ->modalWidth(Width::Large)
                ->modalSubmitActionLabel('Import')
                ->schema([
                    FileUpload::make('file')
                        ->label('File spreadsheet (.xlsx)')
                        ->disk('local')
                        ->directory('pengiriman-imports')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/octet-stream',
                            'application/zip',
                        ])
                        ->maxSize(102400)
                        ->storeFileNamesIn('file_name')
                        ->required(),
                    Toggle::make('replace_existing')
                        ->label('Ganti data hasil import spreadsheet sebelumnya')
                        ->helperText('Untuk cabang yang ada di file ini, supaya import ulang tidak dobel. Data dari upload CSA dan input manual tidak ikut terhapus.')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    ImportShipmentSpreadsheetJob::dispatch(
                        $data['file'],
                        $data['file_name'] ?? basename($data['file']),
                        (bool) ($data['replace_existing'] ?? true),
                        auth()->id(),
                    );

                    Notification::make()
                        ->title('Import spreadsheet sedang diproses')
                        ->body('Berjalan di belakang layar ±1–3 menit. Hasilnya muncul di notifikasi lonceng di kanan atas.')
                        ->success()
                        ->send();
                }),

            CreateAction::make()
                ->label('Tambah Manual')
                ->modalHeading('Tambah Data Pengiriman Manual')
                ->modalWidth('5xl')
                ->createAnother(false)
                ->mutateDataUsing(function (array $data): array {
                    $data['source'] = CsaShipment::SOURCE_MANUAL;
                    $data['kode_gudang'] = $data['kode_gudang'] ?? 'MANUAL';
                    // Admin cabang selalu menambah data untuk cabangnya sendiri
                    $data['target_sheet'] = auth()->user()?->cabangScope() ?? $data['target_sheet'];
                    $data['is_synced'] = false;

                    return $data;
                }),
        ];
    }

    /**
     * Cabang yang sedang dilihat: cabang akun admin cabang, atau pilihan dropdown Cabang.
     */
    private function selectedCabang(): string
    {
        return auth()->user()?->cabangScope()
            ?? ($this->tableFilters['target_sheet']['value'] ?? null)
            ?: 'Semua Cabang';
    }
}

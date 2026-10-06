<?php

namespace App\Filament\Resources\CsaImports\Pages;

use App\Filament\Resources\CsaImports\CsaImportResource;
use App\Jobs\ProcessCsaImportJob;
use App\Models\CsaImport;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Storage;

class ListCsaImports extends ListRecords
{
    protected static string $resource = CsaImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalHeading('Upload Laporan Penjualan CSA')
                ->modalDescription('Pilih file export laporan penjualan CSA (.xlsx) untuk diekstrak dan diagregasikan per Surat Jalan.')
                ->modalSubmitActionLabel('Unggah & Mulai Proses')
                ->modalWidth(Width::Large)
                ->createAnother(false)
                ->using(function (array $data): CsaImport {
                    $relativePath = $data['excel_file'];
                    $fullPath = Storage::disk('local')->path($relativePath);
                    $fileName = basename($relativePath);

                    /** @var CsaImport $record */
                    $record = CsaImport::create([
                        'user_id' => auth()->id(),
                        'file_name' => $fileName,
                        'file_path' => $fullPath,
                        'status' => 'pending',
                        'total_raw_rows' => 0,
                        'total_shipments' => 0,
                        'total_synced' => 0,
                    ]);

                    $autoSync = (bool) ($data['auto_sync'] ?? false);

                    // Dispatch background job to process Excel in Horizon
                    ProcessCsaImportJob::dispatch($record, $autoSync);

                    return $record;
                })
                ->successNotification(
                    Notification::make()
                        ->title('File berhasil diunggah')
                        ->body('Proses ekstraksi dan agregasi data sedang berjalan di antrean latar belakang (Horizon).')
                        ->success()
                ),
        ];
    }
}

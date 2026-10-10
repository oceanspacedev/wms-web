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
                ->label('Upload Laporan')
                ->modalHeading('Upload Laporan CSA')
                ->modalSubmitActionLabel('Upload')
                ->modalWidth(Width::Medium)
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

                    // Upload hanya menyimpan file; ekstraksi (dan sinkron) berjalan di antrean agar request tidak timeout
                    ProcessCsaImportJob::dispatch($record, (bool) ($data['auto_sync'] ?? false));

                    return $record;
                })
                ->successNotification(
                    Notification::make()
                        ->title('File berhasil diunggah')
                        ->body('Data sedang diproses di belakang layar. Status di tabel akan diperbarui otomatis.')
                        ->success()
                ),
        ];
    }
}

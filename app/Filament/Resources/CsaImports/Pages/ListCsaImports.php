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

                    $processImmediately = (bool) ($data['process_immediately'] ?? false);
                    $autoSync = (bool) ($data['auto_sync'] ?? false);

                    if ($processImmediately) {
                        ProcessCsaImportJob::dispatchSync($record, $autoSync, true);
                    } else {
                        ProcessCsaImportJob::dispatch($record, $autoSync, false);
                    }

                    return $record;
                })
                ->successNotification(function (array $data) {
                    $processImmediately = (bool) ($data['process_immediately'] ?? false);

                    return Notification::make()
                        ->title('File berhasil diunggah')
                        ->body($processImmediately ? 'Data telah berhasil langsung diproses dan diekstrak.' : 'Data sedang diproses di antrean queue.')
                        ->success();
                }),
        ];
    }
}

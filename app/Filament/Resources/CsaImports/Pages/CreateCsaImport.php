<?php

namespace App\Filament\Resources\CsaImports\Pages;

use App\Filament\Resources\CsaImports\CsaImportResource;
use App\Jobs\ProcessCsaImportJob;
use App\Models\CsaImport;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CreateCsaImport extends CreateRecord
{
    protected static string $resource = CsaImportResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $relativePath = $data['excel_file'];
        $fullPath = Storage::disk('local')->path($relativePath);
        $fileName = basename($relativePath);

        /** @var CsaImport $record */
        $record = static::getModel()::create([
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

        if ($processImmediately || config('queue.default') === 'sync') {
            ProcessCsaImportJob::dispatchSync($record, $autoSync);

            Notification::make()
                ->title('File Berhasil Diproses')
                ->body('Data berhasil diekstrak dan siap diperiksa.')
                ->success()
                ->send();
        } else {
            ProcessCsaImportJob::dispatch($record, $autoSync);

            Notification::make()
                ->title('File Berhasil Diunggah')
                ->body('Proses ekstraksi sedang berjalan di antrean latar belakang.')
                ->info()
                ->send();
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

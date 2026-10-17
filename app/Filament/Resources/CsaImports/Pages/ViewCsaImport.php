<?php

namespace App\Filament\Resources\CsaImports\Pages;

use App\Filament\Resources\CsaImports\CsaImportResource;
use App\Jobs\ProcessCsaImportJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewCsaImport extends ViewRecord
{
    protected static string $resource = CsaImportResource::class;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getRelationManagersContentComponent(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Hanya untuk import yang belum berhasil: ekstraksi ulang import "Selesai" akan menggandakan data SJ
            Action::make('reprocess')
                ->label('Ekstraksi Ulang File')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => in_array($this->record->status, ['pending', 'failed']))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->update(['status' => 'pending', 'error_message' => null]);

                    ProcessCsaImportJob::dispatch($this->record);

                    Notification::make()
                        ->title('Proses Ulang Dimulai')
                        ->body('File sedang diekstrak kembali oleh background worker.')
                        ->info()
                        ->send();
                }),
        ];
    }
}

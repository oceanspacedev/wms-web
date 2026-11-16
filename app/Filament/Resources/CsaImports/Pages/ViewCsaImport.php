<?php

namespace App\Filament\Resources\CsaImports\Pages;

use App\Filament\Resources\CsaImports\CsaImportResource;
use App\Jobs\ProcessCsaImportJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

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

    public function getTitle(): string|Htmlable
    {
        return 'Laporan '.$this->record->reportLabel();
    }

    /**
     * Periode laporan; nama file tidak ditampilkan karena bisa sangat panjang (tetap terlihat di daftar import).
     */
    public function getSubheading(): ?string
    {
        $record = $this->record;

        return $record->period_start && $record->period_end
            ? $record->period_start->format('d/m').' – '.$record->period_end->format('d/m/Y').' ('.$record->periodDays().' hari)'
            : 'diupload '.$record->created_at?->format('d/m/Y H:i');
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            CsaImportResource::getUrl() => CsaImportResource::getNavigationLabel(),
            'Detail',
        ];
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

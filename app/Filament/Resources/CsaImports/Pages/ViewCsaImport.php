<?php

namespace App\Filament\Resources\CsaImports\Pages;

use App\Filament\Resources\CsaImports\CsaImportResource;
use App\Jobs\ProcessCsaImportJob;
use App\Jobs\SyncToGoogleSheetJob;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
            Action::make('processNow')
                ->label('Proses Sekarang (Langsung)')
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->record->status, ['pending', 'failed']))
                ->action(function (): void {
                    ProcessCsaImportJob::dispatchSync($this->record);

                    $this->record->refresh();

                    if ($this->record->status === 'completed') {
                        Notification::make()
                            ->title('Ekstraksi Berhasil')
                            ->body("Berhasil mengekstrak {$this->record->total_shipments} data Surat Jalan.")
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Ekstraksi Gagal')
                            ->body($this->record->error_message ?: 'Terjadi kesalahan saat memproses file.')
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('syncToSheets')
                ->label('Kirim ke Google Spreadsheet')
                ->icon('heroicon-o-cloud-arrow-up')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === 'completed' && $this->record->total_shipments > 0)
                ->schema([
                    TextInput::make('webhook_url')
                        ->label('Google Apps Script Webhook URL')
                        ->default(config('services.google_sheets.webhook_url') ?: '')
                        ->required()
                        ->helperText('URL Web App Google Apps Script dari spreadsheet tujuan.'),

                    Select::make('target_sheet')
                        ->label('Pilih Sheet Tujuan')
                        ->placeholder('Semua Cabang / Sheet')
                        ->options(function () {
                            if (empty($this->record->summary_by_sheet)) {
                                return [];
                            }
                            $options = [];
                            foreach ($this->record->summary_by_sheet as $sheet => $stats) {
                                $options[$sheet] = "{$sheet} ({$stats['total_sj']} Surat Jalan)";
                            }

                            return $options;
                        }),

                    Toggle::make('run_immediately')
                        ->label('Kirim Langsung (Tanpa Menunggu Queue Worker)')
                        ->default(true)
                        ->helperText('Jika aktif, data langsung dikirim detik ini juga tanpa perlu antrean background.'),
                ])
                ->action(function (array $data): void {
                    $targetSheet = $data['target_sheet'] ?? null;
                    $webhookUrl = $data['webhook_url'];
                    $runImmediately = (bool) ($data['run_immediately'] ?? true);

                    if ($runImmediately) {
                        SyncToGoogleSheetJob::dispatchSync($this->record, $targetSheet, $webhookUrl);
                        $this->record->refresh();

                        Notification::make()
                            ->title('Sinkronisasi Selesai')
                            ->body('Data pengiriman berhasil langsung dikirim ke Google Spreadsheet.')
                            ->success()
                            ->send();
                    } else {
                        SyncToGoogleSheetJob::dispatch($this->record, $targetSheet, $webhookUrl);

                        Notification::make()
                            ->title('Proses Sinkronisasi Dimasukkan ke Antrean')
                            ->body('Data sedang diproses. Pastikan php artisan queue:work sedang berjalan.')
                            ->info()
                            ->send();
                    }
                }),

            Action::make('reprocess')
                ->label('Ekstraksi Ulang File')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (): void {
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

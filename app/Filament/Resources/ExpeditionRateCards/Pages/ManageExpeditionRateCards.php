<?php

namespace App\Filament\Resources\ExpeditionRateCards\Pages;

use App\Filament\Resources\ExpeditionRateCards\ExpeditionRateCardResource;
use App\Jobs\ImportRateCardsJob;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageExpeditionRateCards extends ManageRecords
{
    protected static string $resource = ExpeditionRateCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah Tarif Manual')
                ->icon(null),

            Action::make('importRateCards')
                ->label('Import Excel / Spreadsheet')
                ->icon(null)
                ->color('success')
                ->modalHeading('Import Tarif Kontrak (Rate Cards)')
                ->modalDescription('Unggah file spreadsheet Excel (.xlsx, .xls) atau CSV untuk memperbarui data master tarif ekspedisi.')
                ->modalSubmitActionLabel('Mulai Import Data')
                ->modalWidth('lg')
                ->form([
                    FileUpload::make('attachment')
                        ->label('File Spreadsheet (Excel / CSV)')
                        ->disk('local')
                        ->directory('rate-card-imports')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                            'text/plain',
                            'application/csv',
                        ])
                        ->maxSize(102400)
                        ->storeFileNamesIn('file_name')
                        ->required()
                        ->helperText('Mendukung file unduhan Google Sheets / Excel (.xlsx dan .csv) hingga 100 MB. Diproses di belakang layar; hasilnya muncul di notifikasi lonceng.'),

                    Select::make('mode')
                        ->label('Metode Import')
                        ->options([
                            'upsert' => 'Tambahkan & Update data yang sudah ada (Upsert - Rekomendasi)',
                            'replace' => 'Kosongkan & Gantikan seluruh data (Reset & Import Ulang)',
                        ])
                        ->default('upsert')
                        ->required(),

                    TextInput::make('default_insurance_percent')
                        ->label('Default Persentase Asuransi (%)')
                        ->numeric()
                        ->default(0.2)
                        ->suffix('%')
                        ->required()
                        ->helperText('Nilai default premi asuransi jika tidak tertera di baris tarif (cth: 0.2%).'),
                ])
                ->action(function (array $data): void {
                    $attachment = (string) ($data['attachment'] ?? '');

                    // File pricelist ±200 ribu baris; di dalam request web kena 504 Gateway Time-out
                    ImportRateCardsJob::dispatch(
                        $attachment,
                        (string) ($data['file_name'] ?? basename($attachment)),
                        (string) ($data['mode'] ?? 'upsert'),
                        (float) ($data['default_insurance_percent'] ?? 0.2),
                        auth()->id(),
                    );

                    Notification::make()
                        ->title('Import tarif sedang diproses')
                        ->body('Berjalan di belakang layar ±1–3 menit. Hasilnya muncul di notifikasi lonceng di kanan atas.')
                        ->success()
                        ->send();
                }),
        ];
    }
}

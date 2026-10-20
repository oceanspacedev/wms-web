<?php

namespace App\Filament\Resources\ExpeditionRateCards\Pages;

use App\Filament\Resources\ExpeditionRateCards\ExpeditionRateCardResource;
use App\Filament\Resources\ExpeditionRateCards\Widgets\RateCardImportsWidget;
use App\Jobs\ProcessRateCardImportJob;
use App\Models\RateCardImport;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Livewire;

class ManageExpeditionRateCards extends ManageRecords
{
    protected static string $resource = ExpeditionRateCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah Tarif Manual')
                ->icon(null),

            // Riwayat & status import tampil di modal, tanpa pindah halaman; badge = import yang belum selesai
            Action::make('importHistory')
                ->label('Riwayat Import')
                ->icon(null)
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->can('viewAny', RateCardImport::class) ?? false) && RateCardImport::query()->exists())
                ->badge(fn (): ?int => RateCardImport::query()->whereIn('status', ['pending', 'processing'])->count() ?: null)
                ->badgeColor('warning')
                ->modalHeading('Riwayat Import')
                ->modalWidth('5xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Tutup')
                ->schema([
                    Livewire::make(RateCardImportsWidget::class),
                ]),

            // Alurnya sama dengan Import Penjualan CSA: file dicatat di Riwayat Import lalu diproses di antrean
            Action::make('importRateCards')
                ->label('Import Excel / Spreadsheet')
                ->icon(null)
                ->color('success')
                ->visible(fn (): bool => auth()->user()?->can('create', RateCardImport::class) ?? false)
                ->modalHeading('Import Tarif Kontrak (Rate Cards)')
                ->modalSubmitActionLabel('Upload')
                ->modalWidth('lg')
                ->schema([
                    FileUpload::make('attachment')
                        ->label('File pricelist (Excel / CSV)')
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
                        ->helperText('File .xlsx atau .csv hingga 100 MB. Diproses di belakang layar; statusnya terlihat di tombol Riwayat Import.'),

                    Select::make('mode')
                        ->label('Metode Import')
                        ->options([
                            'upsert' => 'Tambah & update tarif yang sudah ada',
                            'replace' => 'Kosongkan & ganti semua tarif',
                        ])
                        ->default('upsert')
                        ->required(),

                    TextInput::make('default_insurance_percent')
                        ->label('Default premi asuransi')
                        ->numeric()
                        ->default(0.2)
                        ->suffix('%')
                        ->required()
                        ->helperText('Dipakai jika baris tarif tidak mencantumkan premi.'),
                ])
                ->action(function (array $data): void {
                    $path = (string) $data['attachment'];

                    $import = RateCardImport::create([
                        'user_id' => auth()->id(),
                        'file_name' => (string) ($data['file_name'] ?? basename($path)),
                        'file_path' => $path,
                        'mode' => (string) ($data['mode'] ?? 'upsert'),
                        'default_insurance_percent' => (float) ($data['default_insurance_percent'] ?? 0.2),
                        'status' => 'pending',
                    ]);

                    // File pricelist ±200 ribu baris; di dalam request web kena 504 Gateway Time-out
                    ProcessRateCardImportJob::dispatch($import);

                    Notification::make()
                        ->title('File berhasil diunggah')
                        ->body('Tarif sedang diproses di belakang layar. Status di Riwayat Import akan diperbarui otomatis.')
                        ->success()
                        ->send();

                    // Langsung tampilkan Riwayat Import supaya status prosesnya kelihatan
                    $this->replaceMountedAction('importHistory');
                }),
        ];
    }
}

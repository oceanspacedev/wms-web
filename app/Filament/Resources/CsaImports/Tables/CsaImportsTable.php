<?php

namespace App\Filament\Resources\CsaImports\Tables;

use App\Jobs\ProcessCsaImportJob;
use App\Jobs\SyncToGoogleSheetJob;
use App\Models\CsaImport;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CsaImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('file_name')
                    ->label('Nama File')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('user.name')
                    ->label('Pengunggah')
                    ->placeholder('System')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'info',
                        'failed' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'completed' => 'Selesai Diproses',
                        'processing' => 'Sedang Diproses',
                        'failed' => 'Gagal',
                        default => 'Menunggu Antrean',
                    }),

                TextColumn::make('total_raw_rows')
                    ->label('Item Baris')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('total_shipments')
                    ->label('Total SJ')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('total_synced')
                    ->label('SJ Tersinkron')
                    ->state(fn ($record) => $record->shipments()->where('is_synced', true)->count())
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.'))
                    ->sortable()
                    ->color(fn ($state, $record) => $state >= $record->total_shipments && $record->total_shipments > 0 ? 'success' : ($state > 0 ? 'info' : 'gray')),

                TextColumn::make('created_at')
                    ->label('Tanggal Upload')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),

                    Action::make('processNow')
                        ->label('Proses Sekarang')
                        ->icon('heroicon-o-bolt')
                        ->color('warning')
                        ->visible(fn (CsaImport $record): bool => in_array($record->status, ['pending', 'failed']))
                        ->action(function (CsaImport $record): void {
                            ProcessCsaImportJob::dispatchSync($record);

                            $record->refresh();

                            if ($record->status === 'completed') {
                                Notification::make()
                                    ->title('Ekstraksi Berhasil')
                                    ->body("Berhasil mengekstrak {$record->total_shipments} data Surat Jalan.")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Ekstraksi Gagal')
                                    ->body($record->error_message ?: 'Terjadi kesalahan saat memproses file.')
                                    ->danger()
                                    ->send();
                            }
                        }),

                    Action::make('syncToSheets')
                        ->label('Kirim ke Sheet')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->color('success')
                        ->visible(fn (CsaImport $record): bool => $record->status === 'completed' && $record->total_shipments > 0)
                        ->schema(fn (CsaImport $record): array => [
                            TextInput::make('webhook_url')
                                ->label('Google Apps Script Webhook URL')
                                ->default(config('services.google_sheets.webhook_url') ?: '')
                                ->required()
                                ->helperText('URL Web App dari deployment Google Apps Script spreadsheet Anda'),

                            Select::make('target_sheet')
                                ->label('Target Sheet Cabang')
                                ->placeholder('Semua Cabang / Sheet Sekaligus')
                                ->options(function () use ($record) {
                                    if (empty($record->summary_by_sheet)) {
                                        return [];
                                    }
                                    $options = [];
                                    foreach ($record->summary_by_sheet as $sheet => $stats) {
                                        $options[$sheet] = "{$sheet} ({$stats['total_sj']} SJ)";
                                    }

                                    return $options;
                                }),
                        ])
                        ->action(function (CsaImport $record, array $data): void {
                            $targetSheet = $data['target_sheet'] ?? null;
                            $webhookUrl = $data['webhook_url'];

                            SyncToGoogleSheetJob::dispatch($record, $targetSheet, $webhookUrl);

                            Notification::make()
                                ->title('Sinkronisasi Dikirim ke Antrean')
                                ->body('Data sedang dikirim ke Google Spreadsheet via background worker.')
                                ->success()
                                ->send();
                        }),

                    Action::make('reprocess')
                        ->label('Proses Ulang')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(function (CsaImport $record): void {
                            ProcessCsaImportJob::dispatch($record);

                            Notification::make()
                                ->title('Proses Ulang Dimulai')
                                ->body('File sedang diekstraksi ulang oleh background worker.')
                                ->info()
                                ->send();
                        }),

                    DeleteAction::make(),
                ])
                    ->label('Aksi')
                    ->tooltip('Pilihan Aksi')
                    ->icon('heroicon-m-ellipsis-vertical'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

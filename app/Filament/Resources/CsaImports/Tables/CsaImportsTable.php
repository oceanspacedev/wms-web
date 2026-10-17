<?php

namespace App\Filament\Resources\CsaImports\Tables;

use App\Jobs\ProcessCsaImportJob;
use App\Models\CsaImport;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
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
                    })
                    ->tooltip(fn (CsaImport $record): ?string => $record->status === 'failed' ? $record->error_message : null),

                TextColumn::make('total_raw_rows')
                    ->label('Item Baris')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('total_shipments')
                    ->label('Total SJ')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.'))
                    ->description(function ($record): ?string {
                        if ($record->status !== 'completed' || ($record->total_updated === 0 && $record->total_skipped === 0)) {
                            return null;
                        }

                        $parts = [number_format($record->total_new, 0, ',', '.').' baru'];

                        if ($record->total_updated > 0) {
                            $parts[] = number_format($record->total_updated, 0, ',', '.').' diperbarui';
                        }

                        if ($record->total_skipped > 0) {
                            $parts[] = number_format($record->total_skipped, 0, ',', '.').' sudah ada';
                        }

                        return implode(' · ', $parts);
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Tanggal Upload')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            // Ekstraksi berjalan di antrean, jadi status dan jumlah SJ perlu diperbarui berkala
            ->poll('10s')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),

                    // Hanya untuk import yang belum berhasil: ekstraksi ulang import "Selesai" akan menggandakan data SJ
                    Action::make('reprocess')
                        ->label('Proses Ulang')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->visible(fn (CsaImport $record): bool => in_array($record->status, ['pending', 'failed']))
                        ->requiresConfirmation()
                        ->action(function (CsaImport $record): void {
                            $record->update(['status' => 'pending', 'error_message' => null]);

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

<?php

namespace App\Filament\Resources\ExpeditionRateCards\Widgets;

use App\Jobs\ProcessRateCardImportJob;
use App\Models\RateCardImport;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Isi modal "Riwayat Import" di halaman Tarif Kontrak; statusnya diperbarui otomatis seperti Import Penjualan CSA.
 */
class RateCardImportsWidget extends TableWidget
{
    public function table(Table $table): Table
    {
        return $table
            // Judul sudah ada di modal
            ->heading(null)
            ->query(RateCardImport::query()->with('user'))
            ->defaultSort('created_at', 'desc')
            ->paginated([5])
            // Import berjalan di antrean, jadi status dan jumlah tarif perlu diperbarui berkala
            ->poll('10s')
            ->columns([
                TextColumn::make('file_name')
                    ->label('Nama File')
                    ->weight('bold')
                    ->wrap()
                    ->description(fn (RateCardImport $record): string => RateCardImport::MODE_LABELS[$record->mode] ?? $record->mode),

                TextColumn::make('user.name')
                    ->label('Pengunggah')
                    ->placeholder('System'),

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
                    ->description(fn (RateCardImport $record): ?string => $record->status === 'failed'
                        ? str($record->error_message)->limit(80)->toString()
                        : null)
                    ->tooltip(fn (RateCardImport $record): ?string => $record->status === 'failed' ? $record->error_message : null),

                TextColumn::make('total_unique')
                    ->label('Tarif')
                    ->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.'))
                    ->description(fn (RateCardImport $record): ?string => $record->status === 'completed'
                        ? number_format($record->total_created, 0, ',', '.').' baru · '.number_format($record->total_updated, 0, ',', '.').' diperbarui'
                        : null),

                TextColumn::make('created_at')
                    ->label('Tanggal Upload')
                    ->dateTime('d M Y H:i'),
            ])
            ->recordActions([
                ActionGroup::make([
                    // Hanya untuk import yang belum berhasil; file yang sudah selesai dihapus
                    Action::make('reprocess')
                        ->label('Proses Ulang')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->visible(fn (RateCardImport $record): bool => $record->canReprocess())
                        ->requiresConfirmation()
                        ->action(function (RateCardImport $record): void {
                            $record->update(['status' => 'pending', 'error_message' => null]);

                            ProcessRateCardImportJob::dispatch($record);

                            Notification::make()
                                ->title('Proses Ulang Dimulai')
                                ->body('File sedang diproses ulang oleh background worker.')
                                ->info()
                                ->send();
                        }),

                    DeleteAction::make(),
                ])
                    ->label('Aksi')
                    ->tooltip('Pilihan Aksi')
                    ->icon('heroicon-m-ellipsis-vertical'),
            ]);
    }
}

<?php

namespace App\Filament\Resources\WarehouseDocuments\Tables;

use App\Jobs\SendWarehouseDocumentToArsipJob;
use App\Models\WarehouseDocument;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class WarehouseDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Tanggal Upload')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('po_sj_number')
                    ->label('No. PO/SJ')
                    ->description(fn (WarehouseDocument $record): ?string => $record->program_title)
                    ->placeholder('-')
                    ->searchable(['po_sj_number', 'program_title']),

                TextColumn::make('kode_gudang')
                    ->label('Kode Gudang')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('original_name')
                    ->label('File')
                    ->limit(30)
                    ->tooltip(fn (WarehouseDocument $record): string => $record->original_name),

                TextColumn::make('user.name')
                    ->label('Diunggah Oleh')
                    ->placeholder('-'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        WarehouseDocument::STATUS_SENT => 'success',
                        WarehouseDocument::STATUS_FAILED => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        WarehouseDocument::STATUS_SENT => 'Terkirim ke Arsip',
                        WarehouseDocument::STATUS_FAILED => 'Gagal',
                        default => 'Menunggu Dikirim',
                    })
                    ->tooltip(fn (WarehouseDocument $record): ?string => $record->error_message),

                TextColumn::make('sent_at')
                    ->label('Waktu Terkirim')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            // Pengiriman ke SCM Arsip berjalan di antrean, jadi status perlu diperbarui berkala
            ->poll('10s')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        WarehouseDocument::STATUS_PENDING => 'Menunggu Dikirim',
                        WarehouseDocument::STATUS_SENT => 'Terkirim ke Arsip',
                        WarehouseDocument::STATUS_FAILED => 'Gagal',
                    ]),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Unduh')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn (WarehouseDocument $record) => Storage::disk('local')->download($record->file_path, $record->original_name)),

                Action::make('resend')
                    ->label('Kirim Ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (WarehouseDocument $record): bool => $record->status === WarehouseDocument::STATUS_FAILED)
                    ->requiresConfirmation()
                    ->action(function (WarehouseDocument $record): void {
                        $record->update([
                            'status' => WarehouseDocument::STATUS_PENDING,
                            'error_message' => null,
                        ]);

                        SendWarehouseDocumentToArsipJob::dispatch($record);

                        Notification::make()
                            ->title('Dokumen dikirim ulang')
                            ->body('Dokumen sedang dikirim ke SCM Arsip di belakang layar.')
                            ->info()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Belum ada dokumen')
            ->emptyStateDescription('Upload Memo/DO untuk dikirim ke SCM Arsip.');
    }
}

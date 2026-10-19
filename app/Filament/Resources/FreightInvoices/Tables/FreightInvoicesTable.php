<?php

namespace App\Filament\Resources\FreightInvoices\Tables;

use App\Jobs\ProcessFreightReconciliationJob;
use App\Models\FreightInvoice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FreightInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Nomor Faktur')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('expedition.name')
                    ->label('Ekspedisi')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                TextColumn::make('invoice_date')
                    ->label('Tgl Faktur')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status Audit')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'audited' => 'primary',
                        'processing' => 'info',
                        'disputed' => 'danger',
                        'paid' => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => 'Disetujui',
                        'audited' => 'Selesai Diaudit',
                        'processing' => 'Sedang Diaudit',
                        'disputed' => 'Ada Selisih / Sanggahan',
                        'paid' => 'Sudah Dibayar',
                        default => 'Draft',
                    }),

                TextColumn::make('total_items_count')
                    ->label('Total Resi')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('total_billed_amount')
                    ->label('Total Ditagih')
                    ->money('IDR', locale: 'id')
                    ->sortable(),

                TextColumn::make('total_approved_amount')
                    ->label('Total Disetujui')
                    ->money('IDR', locale: 'id')
                    ->color('success')
                    ->sortable(),

                TextColumn::make('total_discrepancy_amount')
                    ->label('Koreksi / Selisih')
                    ->money('IDR', locale: 'id')
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Waktu Upload')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->filters([
                SelectFilter::make('expedition_id')
                    ->label('Filter Ekspedisi')
                    ->relationship('expedition', 'name'),

                SelectFilter::make('status')
                    ->label('Filter Status')
                    ->options([
                        'processing' => 'Sedang Diaudit',
                        'audited' => 'Selesai Diaudit',
                        'approved' => 'Disetujui',
                        'disputed' => 'Ada Selisih',
                        'paid' => 'Sudah Dibayar',
                    ]),
            ])
            // Satu tombol titik tiga supaya baris tidak memanjang ke kanan
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),

                    Action::make('reAudit')
                        ->label('Audit Ulang')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(function (FreightInvoice $record): void {
                            ProcessFreightReconciliationJob::dispatch($record);

                            Notification::make()
                                ->title('Audit Ulang Dimulai')
                                ->body('Proses rekonsiliasi ulang sedang dijalankan di antrean.')
                                ->info()
                                ->send();
                        }),

                    Action::make('approve')
                        ->label('Setujui Tagihan')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn (FreightInvoice $record) => in_array($record->status, ['audited', 'disputed']))
                        ->requiresConfirmation()
                        ->action(function (FreightInvoice $record): void {
                            $record->update(['status' => 'approved']);

                            Notification::make()
                                ->title('Faktur Disetujui')
                                ->body("Faktur #{$record->invoice_number} disetujui sebesar Rp ".number_format($record->total_approved_amount, 0, ',', '.'))
                                ->success()
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

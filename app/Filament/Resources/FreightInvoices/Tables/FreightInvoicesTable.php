<?php

namespace App\Filament\Resources\FreightInvoices\Tables;

use App\Filament\Resources\FreightInvoices\Actions\ExplainTotalsAction;
use App\Filament\Resources\FreightInvoices\Actions\ReauditAction;
use App\Models\FreightInvoice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FreightInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('expedition'))
            // Ekspedisi, tanggal & jumlah resi jadi keterangan di bawah nomor invoice supaya tabel tidak melebar
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    // Nama invoice ditampilkan utuh (turun ke baris berikutnya kalau panjang)
                    ->wrap()
                    ->description(fn (FreightInvoice $record): string => implode(' · ', array_filter([
                        $record->expedition?->name,
                        $record->invoice_date?->format('d/m/Y'),
                        number_format((int) $record->total_items_count, 0, ',', '.').' resi',
                    ]))),

                TextColumn::make('expedition.name')
                    ->label('Ekspedisi')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('invoice_date')
                    ->label('Tgl Invoice')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_billed_amount')
                    ->label('Ditagih')
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->sortable(),

                TextColumn::make('total_approved_amount')
                    ->label('Disetujui')
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->color('success')
                    ->sortable(),

                TextColumn::make('total_discrepancy_amount')
                    ->label('Koreksi')
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('total_unverified_amount')
                    ->label('Belum Terverifikasi')
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray')
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

                    EditAction::make(),

                    ExplainTotalsAction::make(),

                    ReauditAction::make(),

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

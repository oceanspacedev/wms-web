<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Models\PurchaseOrder;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('no_do')
                    ->label('No. DO')
                    ->weight('bold')
                    ->copyable()
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('no_po')
                    ->label('No. PO')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->placeholder('Belum dicocokkan'),

                TextColumn::make('tanggal_do')
                    ->label('Tgl DO')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('nama_supplier')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->limit(25)
                    ->tooltip(fn ($record) => $record->nama_supplier),

                TextColumn::make('nama_gudang')
                    ->label('Gudang Tujuan')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('tanggal_datang')
                    ->label('Tgl Datang')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('qty_koli')
                    ->label('Koli')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('qty_unit')
                    ->label('Unit')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_nominal')
                    ->label('Total Nilai')
                    ->money('IDR', locale: 'id')
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('status_penerimaan')
                    ->label('Status Penerimaan')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Lengkap' => 'success',
                        'Kurang' => 'warning',
                        'Rusak' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('status_verifikasi_finance')
                    ->label('Verifikasi Finance')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Disetujui', 'Selesai' => 'success',
                        'Menunggu Pemeriksaan' => 'warning',
                        'Ditolak' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                ImageColumn::make('foto_penerimaan')
                    ->label('Foto Penerimaan')
                    ->disk('public')
                    ->circular()
                    ->checkFileExistence(false)
                    ->toggleable(),

                TextColumn::make('nama_kurir_ekspedisi')
                    ->label('Kurir / Ekspedisi')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('no_resi')
                    ->label('No. Resi')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('penerima_gudang')
                    ->label('Penerima Gudang')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Dibuat Pada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status_penerimaan')
                    ->label('Status Penerimaan')
                    ->options([
                        'Lengkap' => 'Lengkap (Sesuai PO)',
                        'Kurang' => 'Kurang / Sebagian',
                        'Rusak' => 'Ada Rusak / Cacat',
                    ]),

                SelectFilter::make('status_verifikasi_finance')
                    ->label('Verifikasi Finance')
                    ->options([
                        'Menunggu Pemeriksaan' => 'Menunggu Pemeriksaan',
                        'Disetujui' => 'Disetujui',
                        'Ditolak' => 'Ditolak',
                    ]),

                SelectFilter::make('nama_gudang')
                    ->label('Gudang / Depo')
                    ->options(fn () => PurchaseOrder::whereNotNull('nama_gudang')->where('nama_gudang', '!=', '')->distinct()->pluck('nama_gudang', 'nama_gudang')->toArray()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

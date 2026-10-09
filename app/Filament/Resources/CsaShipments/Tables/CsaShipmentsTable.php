<?php

namespace App\Filament\Resources\CsaShipments\Tables;

use App\Models\CsaShipment;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CsaShipmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('no_sj')
                    ->label('Nomor SJ')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('tanggal_kirim')
                    ->label('Tgl Kirim')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('target_sheet')
                    ->label('Depo / Sheet')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('tujuan_dealer')
                    ->label('Tujuan / Dealer')
                    ->searchable()
                    ->limit(25)
                    ->tooltip(fn ($record) => $record->tujuan_dealer),

                TextColumn::make('nama_kota')
                    ->label('Kota')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('brand')
                    ->label('Brand')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('qty_unit')
                    ->label('Unit')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('total_nominal_sj')
                    ->label('Nominal SJ')
                    ->money('IDR', locale: 'id')
                    ->sortable(),

                TextColumn::make('nama_ekspedisi')
                    ->label('Ekspedisi')
                    ->badge()
                    ->color('info')
                    ->placeholder('-'),

                TextColumn::make('no_resi_awb')
                    ->label('No. Resi')
                    ->placeholder('-')
                    ->copyable()
                    ->searchable(),

                IconColumn::make('is_synced')
                    ->label('Synced')
                    ->boolean()
                    ->trueIcon(fn (CsaShipment $record): string => $record->already_in_sheet ? 'heroicon-o-document-duplicate' : 'heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor(fn (CsaShipment $record): string => $record->already_in_sheet ? 'info' : 'success')
                    ->falseColor('warning')
                    ->tooltip(fn (CsaShipment $record): string => match (true) {
                        ! $record->is_synced => 'Belum terkirim ke sheet',
                        $record->already_in_sheet => 'Data yang sama (No SJ & total nominal) sudah ada di sheet, dilewati & tidak ditimpa',
                        default => 'Ditulis ke sheet',
                    })
                    ->sortable(),

                TextColumn::make('reff_note')
                    ->label('Reff Note')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ket_isi_unit')
                    ->label('Ringkasan Barang')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('csa_import_id')
                    ->label('Batch Import ID')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('tanggal_kirim', 'desc')
            ->filters([
                SelectFilter::make('target_sheet')
                    ->label('Filter Depo / Sheet')
                    ->options(fn () => CsaShipment::distinct()->pluck('target_sheet', 'target_sheet')->toArray()),

                SelectFilter::make('brand')
                    ->label('Filter Brand')
                    ->options(fn () => CsaShipment::whereNotNull('brand')->where('brand', '!=', '')->distinct()->pluck('brand', 'brand')->toArray()),

                TernaryFilter::make('is_synced')
                    ->label('Status Sinkronisasi')
                    ->trueLabel('Sudah Terkirim ke Sheet')
                    ->falseLabel('Belum Terkirim ke Sheet'),

                TernaryFilter::make('already_in_sheet')
                    ->label('Data Sama Sudah Ada di Sheet')
                    ->trueLabel('Ya, dilewati (tidak ditimpa)')
                    ->falseLabel('Tidak, ditulis baru'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

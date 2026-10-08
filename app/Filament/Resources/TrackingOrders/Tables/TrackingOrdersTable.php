<?php

namespace App\Filament\Resources\TrackingOrders\Tables;

use App\Models\TrackingOrder;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TrackingOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama_dealer')
                    ->label('Nama Dealer')
                    ->searchable()
                    ->sortable()
                    ->limit(25)
                    ->tooltip(fn ($record) => $record->nama_dealer),

                TextColumn::make('no_sj')
                    ->label('Nomer Surat Jalan')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('alamat_dealer')
                    ->label('Alamat Dealer')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->alamat_dealer)
                    ->searchable(),

                TextColumn::make('jumlah_value_nota')
                    ->label('Jumlah Value Nota')
                    ->numeric(decimalPlaces: 0)
                    ->sortable(),

                TextColumn::make('tanggal_nota')
                    ->label('Tanggal Nota')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('tanggal_pengiriman')
                    ->label('Tanggal Pengiriman')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('nama_pengirim')
                    ->label('Nama Pengirim')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('nama_penerima')
                    ->label('Nama Penerima')
                    ->searchable()
                    ->placeholder('-'),

                ImageColumn::make('foto_nota_sj')
                    ->label('Foto Nota Surat Jalan')
                    ->circular()
                    ->checkFileExistence(false),

                ImageColumn::make('foto_penerima')
                    ->label('Foto Penerima')
                    ->circular()
                    ->checkFileExistence(false),

                TextColumn::make('address')
                    ->label('Address')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->address)
                    ->searchable(),

                TextColumn::make('latitude')
                    ->label('Lat')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('longitude')
                    ->label('Long')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'DELIVERED' => 'success',
                        'IN_TRANSIT' => 'warning',
                        'PENDING' => 'gray',
                        'RETURNED' => 'danger',
                        default => 'primary',
                    })
                    ->sortable(),
            ])
            ->defaultSort('tanggal_pengiriman', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status Pengiriman')
                    ->options([
                        'DELIVERED' => 'Terkirim (Delivered)',
                        'IN_TRANSIT' => 'Dalam Perjalanan (In Transit)',
                        'PENDING' => 'Menunggu Kirim (Pending)',
                        'RETURNED' => 'Retur (Returned)',
                    ]),

                SelectFilter::make('nama_pengirim')
                    ->label('Filter Driver / Pengirim')
                    ->options(fn () => TrackingOrder::whereNotNull('nama_pengirim')->where('nama_pengirim', '!=', '')->distinct()->pluck('nama_pengirim', 'nama_pengirim')->toArray()),
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

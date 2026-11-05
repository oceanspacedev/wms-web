<?php

namespace App\Filament\Resources\TrackingOrders\Tables;

use App\Filament\Resources\TrackingOrders\TrackingOrderResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

                // Otomatis dari depo akun kurir saat SJ dicatat
                TextColumn::make('depo.nama')
                    ->label('Depo')
                    ->placeholder('-')
                    ->sortable(),

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
            ])
            ->defaultSort('tanggal_pengiriman', 'desc')
            ->filters([
                SelectFilter::make('nama_pengirim')
                    ->label('Filter Driver / Pengirim')
                    ->options(fn () => TrackingOrderResource::getEloquentQuery()->whereNotNull('nama_pengirim')->where('nama_pengirim', '!=', '')->distinct()->pluck('nama_pengirim', 'nama_pengirim')->toArray()),

                SelectFilter::make('depo')
                    ->label('Depo')
                    ->relationship('depo', 'nama')
                    ->searchable()
                    ->preload(),

                // Untuk export per periode
                Filter::make('periode')
                    ->label('Periode Tanggal Pengiriman')
                    ->schema([
                        DatePicker::make('dari')->label('Tanggal pengiriman dari'),
                        DatePicker::make('sampai')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['dari'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('tanggal_pengiriman', '>=', $date))
                        ->when($data['sampai'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('tanggal_pengiriman', '<=', $date)))
                    ->indicateUsing(function (array $data): ?string {
                        if (blank($data['dari'] ?? null) && blank($data['sampai'] ?? null)) {
                            return null;
                        }

                        return 'Pengiriman: '.($data['dari'] ?? '…').' s/d '.($data['sampai'] ?? '…');
                    }),
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

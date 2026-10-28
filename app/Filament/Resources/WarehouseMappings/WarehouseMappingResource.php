<?php

namespace App\Filament\Resources\WarehouseMappings;

use App\Filament\Resources\WarehouseMappings\Pages\ManageWarehouseMappings;
use App\Models\WarehouseMapping;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarehouseMappingResource extends Resource
{
    protected static ?string $model = WarehouseMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static \UnitEnum|string|null $navigationGroup = 'Logistik & Ekspedisi';

    protected static ?string $navigationLabel = 'Pemetaan Gudang ke Cabang';

    protected static ?string $modelLabel = 'Pemetaan Gudang';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('csa_code')
                    ->label('Kode Gudang di CSA')
                    ->placeholder('Contoh: GMCRB, GMBDG, WMONL')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->columnSpanFull(),

                TextInput::make('csa_name')
                    ->label('Nama Gudang di CSA')
                    ->placeholder('Contoh: GUDANG MSIS CIREBON')
                    ->columnSpanFull(),

                TextInput::make('target_sheet')
                    ->label('Cabang di Data Pengiriman')
                    ->placeholder('Contoh: CIREBON, BANDUNG, JAKARTA PIK')
                    ->required()
                    ->columnSpanFull(),

                TextInput::make('region')
                    ->label('Region Alokasi Stok')
                    ->placeholder('Contoh: JAWA BARAT')
                    ->datalist(fn (): array => WarehouseMapping::query()->whereNotNull('region')->distinct()->orderBy('region')->pluck('region')->all())
                    ->helperText('Gudang satu region saling kirim stok; PUSAT bisa kirim ke dan terima dari semua region. Kosongkan kalau gudang tidak ikut (online, retur, sales).')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null)
                    ->maxLength(50)
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('csa_code')
                    ->label('Kode CSA')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('csa_name')
                    ->label('Nama Gudang CSA')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('target_sheet')
                    ->label('Cabang')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('region')
                    ->label('Region')
                    ->placeholder('Tidak ikut alokasi')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->modalWidth(Width::Medium),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWarehouseMappings::route('/'),
        ];
    }
}

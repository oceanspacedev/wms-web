<?php

namespace App\Filament\Resources\Depos;

use App\Filament\Resources\Depos\Pages\ManageDepos;
use App\Models\Depo;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master depo: kode gudang + nama. Akun kurir/admin memilih depo dari sini, dan surat jalan ikut mencatat
 * depo kurirnya; nama/kode yang diedit langsung terbaca di akun dan surat jalan.
 */
class DepoResource extends Resource
{
    protected static ?string $model = Depo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static \UnitEnum|string|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Master Depo';

    protected static ?string $modelLabel = 'Depo';

    protected static ?string $pluralModelLabel = 'Depo';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('kode')
                    ->label('Kode Gudang')
                    ->placeholder('Contoh: GMPLB')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),

                TextInput::make('nama')
                    ->label('Nama Depo')
                    ->placeholder('Contoh: PALEMBANG')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['users', 'trackingOrders']))
            ->defaultSort('nama')
            ->paginated(false)
            ->columns([
                TextColumn::make('kode')
                    ->label('Kode Gudang')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('nama')
                    ->label('Nama Depo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('users_count')
                    ->label('Akun')
                    ->numeric(locale: 'id')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::Medium),
                // Depo yang masih dipakai akun atau surat jalan tidak bisa dihapus
                DeleteAction::make()
                    ->hidden(fn (Depo $record): bool => $record->users_count > 0 || $record->tracking_orders_count > 0),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDepos::route('/'),
        ];
    }
}

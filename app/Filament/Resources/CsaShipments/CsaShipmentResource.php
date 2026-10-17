<?php

namespace App\Filament\Resources\CsaShipments;

use App\Filament\Resources\CsaShipments\Pages\ListCsaShipments;
use App\Filament\Resources\CsaShipments\Schemas\DataPengirimanForm;
use App\Filament\Resources\CsaShipments\Tables\DataPengirimanTable;
use App\Models\CsaShipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data Pengiriman: pengganti spreadsheet "Update Pengiriman" per cabang.
 * Baris dari upload CSA masuk otomatis; admin cabang melengkapi ekspedisi, resi, status, dll.
 */
class CsaShipmentResource extends Resource
{
    protected static ?string $model = CsaShipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static \UnitEnum|string|null $navigationGroup = 'Logistik & Ekspedisi';

    protected static ?string $navigationLabel = 'Data Pengiriman';

    protected static ?string $modelLabel = 'Data Pengiriman';

    protected static ?string $pluralModelLabel = 'Data Pengiriman';

    protected static ?string $slug = 'data-pengiriman';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return DataPengirimanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DataPengirimanTable::configure($table);
    }

    /**
     * Admin cabang hanya bisa melihat & mengisi data cabangnya sendiri.
     *
     * @return Builder<CsaShipment>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCsaShipments::route('/'),
        ];
    }
}

<?php

namespace App\Filament\Resources\TrackingOrders;

use App\Filament\Resources\TrackingOrders\Pages\CreateTrackingOrder;
use App\Filament\Resources\TrackingOrders\Pages\EditTrackingOrder;
use App\Filament\Resources\TrackingOrders\Pages\ListTrackingOrders;
use App\Filament\Resources\TrackingOrders\Pages\ViewTrackingOrder;
use App\Filament\Resources\TrackingOrders\Schemas\TrackingOrderForm;
use App\Filament\Resources\TrackingOrders\Tables\TrackingOrdersTable;
use App\Models\TrackingOrder;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TrackingOrderResource extends Resource
{
    protected static ?string $model = TrackingOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static \UnitEnum|string|null $navigationGroup = 'Tracking Order';

    protected static ?string $navigationLabel = 'Surat Jalan';

    protected static ?string $modelLabel = 'Surat Jalan';

    protected static ?string $pluralModelLabel = 'Surat Jalan';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return TrackingOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TrackingOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Sama dengan API mobile: selain super admin hanya melihat data yang dicatat akunnya sendiri.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user instanceof User ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrackingOrders::route('/'),
            'create' => CreateTrackingOrder::route('/create'),
            'view' => ViewTrackingOrder::route('/{record}'),
            'edit' => EditTrackingOrder::route('/{record}/edit'),
        ];
    }
}

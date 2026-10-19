<?php

namespace App\Filament\Resources\StockAllocations;

use App\Filament\Resources\StockAllocations\Pages\ListStockAllocations;
use App\Filament\Resources\StockAllocations\Pages\ViewStockAllocation;
use App\Filament\Resources\StockAllocations\RelationManagers\LinesRelationManager;
use App\Filament\Resources\StockAllocations\RelationManagers\TransfersRelationManager;
use App\Filament\Resources\StockAllocations\Schemas\StockAllocationInfolist;
use App\Filament\Resources\StockAllocations\Tables\StockAllocationsTable;
use App\Models\StockAllocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StockAllocationResource extends Resource
{
    protected static ?string $model = StockAllocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static \UnitEnum|string|null $navigationGroup = 'Logistik & Ekspedisi';

    protected static ?string $navigationLabel = 'Alokasi Stok';

    protected static ?string $modelLabel = 'Alokasi Stok';

    protected static ?string $pluralModelLabel = 'Alokasi Stok';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 5;

    public static function infolist(Schema $schema): Schema
    {
        return StockAllocationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockAllocationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TransfersRelationManager::class,
            LinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockAllocations::route('/'),
            'view' => ViewStockAllocation::route('/{record}'),
        ];
    }

    public static function units(int|float|null $qty): string
    {
        return number_format((int) $qty, 0, ',', '.').' unit';
    }

    public static function days(?float $dos): string
    {
        return $dos === null ? '–' : number_format($dos, 0, ',', '.');
    }
}

<?php

namespace App\Filament\Resources\FreightInvoices;

use App\Filament\Resources\FreightInvoices\Pages\CreateFreightInvoice;
use App\Filament\Resources\FreightInvoices\Pages\EditFreightInvoice;
use App\Filament\Resources\FreightInvoices\Pages\ListFreightInvoices;
use App\Filament\Resources\FreightInvoices\Pages\ViewFreightInvoice;
use App\Filament\Resources\FreightInvoices\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\FreightInvoices\Schemas\FreightInvoiceForm;
use App\Filament\Resources\FreightInvoices\Schemas\FreightInvoiceInfolist;
use App\Filament\Resources\FreightInvoices\Tables\FreightInvoicesTable;
use App\Models\FreightInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FreightInvoiceResource extends Resource
{
    protected static ?string $model = FreightInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static \UnitEnum|string|null $navigationGroup = 'Audit';

    protected static ?string $navigationLabel = 'Rekonsiliasi Invoice';

    protected static ?string $modelLabel = 'Invoice Tagihan Ekspedisi';

    protected static ?string $pluralModelLabel = 'Rekonsiliasi Tagihan Ekspedisi';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return FreightInvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FreightInvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FreightInvoicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFreightInvoices::route('/'),
            'create' => CreateFreightInvoice::route('/create'),
            'view' => ViewFreightInvoice::route('/{record}'),
            'edit' => EditFreightInvoice::route('/{record}/edit'),
        ];
    }
}

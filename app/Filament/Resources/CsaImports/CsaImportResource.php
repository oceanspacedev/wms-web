<?php

namespace App\Filament\Resources\CsaImports;

use App\Filament\Resources\CsaImports\Pages\EditCsaImport;
use App\Filament\Resources\CsaImports\Pages\ListCsaImports;
use App\Filament\Resources\CsaImports\Pages\ViewCsaImport;
use App\Filament\Resources\CsaImports\Schemas\CsaImportForm;
use App\Filament\Resources\CsaImports\Schemas\CsaImportInfolist;
use App\Filament\Resources\CsaImports\Tables\CsaImportsTable;
use App\Models\CsaImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CsaImportResource extends Resource
{
    protected static ?string $model = CsaImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static \UnitEnum|string|null $navigationGroup = 'Logistik & Ekspedisi';

    protected static ?string $navigationLabel = 'Import Penjualan CSA';

    protected static ?string $modelLabel = 'Laporan Penjualan CSA';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return CsaImportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CsaImportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CsaImportsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCsaImports::route('/'),
            'view' => ViewCsaImport::route('/{record}'),
            'edit' => EditCsaImport::route('/{record}/edit'),
        ];
    }
}

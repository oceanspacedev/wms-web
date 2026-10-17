<?php

namespace App\Filament\Resources\WarehouseDocuments;

use App\Filament\Resources\WarehouseDocuments\Pages\ListWarehouseDocuments;
use App\Filament\Resources\WarehouseDocuments\Schemas\WarehouseDocumentForm;
use App\Filament\Resources\WarehouseDocuments\Tables\WarehouseDocumentsTable;
use App\Models\User;
use App\Models\WarehouseDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WarehouseDocumentResource extends Resource
{
    protected static ?string $model = WarehouseDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static \UnitEnum|string|null $navigationGroup = 'Logistik & Ekspedisi';

    protected static ?string $navigationLabel = 'Dokumen Gudang';

    protected static ?string $modelLabel = 'Dokumen Gudang';

    protected static ?string $pluralModelLabel = 'Dokumen Gudang';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return WarehouseDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarehouseDocumentsTable::configure($table);
    }

    /**
     * Petugas gudang hanya melihat dokumen untuk kode gudang di akunnya sendiri.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user');
        $user = auth()->user();

        if ($user instanceof User && $user->isWarehouseStaff()) {
            $query->where('kode_gudang', (string) $user->kode_gudang_arsip);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarehouseDocuments::route('/'),
        ];
    }
}

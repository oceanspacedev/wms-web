<?php

namespace App\Filament\Resources\CsaImports\RelationManagers;

use App\Filament\Resources\CsaShipments\Schemas\CsaShipmentForm;
use App\Filament\Resources\CsaShipments\Tables\CsaShipmentsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipments';

    protected static ?string $title = 'Data Surat Jalan Pengiriman (Upload Ini)';

    protected static ?string $modelLabel = 'Surat Jalan';

    protected static ?string $pluralModelLabel = 'Daftar Surat Jalan';

    public function form(Schema $schema): Schema
    {
        return CsaShipmentForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return CsaShipmentsTable::configure($table)
            ->recordTitleAttribute('no_sj');
    }
}

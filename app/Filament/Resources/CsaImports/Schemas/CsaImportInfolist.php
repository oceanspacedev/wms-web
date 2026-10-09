<?php

namespace App\Filament\Resources\CsaImports\Schemas;

use Filament\Schemas\Schema;

class CsaImportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Halaman view difokuskan langsung ke tabel data pengiriman (ShipmentsRelationManager)
            ]);
    }
}

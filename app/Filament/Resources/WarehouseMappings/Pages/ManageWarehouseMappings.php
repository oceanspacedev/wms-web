<?php

namespace App\Filament\Resources\WarehouseMappings\Pages;

use App\Filament\Resources\WarehouseMappings\WarehouseMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageWarehouseMappings extends ManageRecords
{
    protected static string $resource = WarehouseMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->slideOver()
                ->modalWidth(Width::Medium),
        ];
    }
}

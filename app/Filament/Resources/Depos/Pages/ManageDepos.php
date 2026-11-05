<?php

namespace App\Filament\Resources\Depos\Pages;

use App\Filament\Resources\Depos\DepoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageDepos extends ManageRecords
{
    protected static string $resource = DepoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalWidth(Width::Medium),
        ];
    }
}

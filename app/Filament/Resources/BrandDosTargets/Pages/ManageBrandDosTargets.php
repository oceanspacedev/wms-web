<?php

namespace App\Filament\Resources\BrandDosTargets\Pages;

use App\Filament\Resources\BrandDosTargets\BrandDosTargetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageBrandDosTargets extends ManageRecords
{
    protected static string $resource = BrandDosTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalWidth(Width::Medium),
        ];
    }
}

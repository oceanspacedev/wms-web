<?php

namespace App\Filament\Resources\TrackingOrders\Pages;

use App\Filament\Resources\TrackingOrders\TrackingOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrackingOrder extends CreateRecord
{
    protected static string $resource = TrackingOrderResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}

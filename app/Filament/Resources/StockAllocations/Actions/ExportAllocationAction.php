<?php

namespace App\Filament\Resources\StockAllocations\Actions;

use App\Models\StockAllocation;
use App\Services\StockAllocationExporter;
use Filament\Actions\Action;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportAllocationAction
{
    public static function make(): Action
    {
        return Action::make('export')
            ->label('Export Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->action(fn (StockAllocation $record): StreamedResponse => app(StockAllocationExporter::class)->download($record));
    }
}

<?php

namespace App\Filament\Resources\StockAllocations\Schemas;

use App\Filament\Resources\StockAllocations\StockAllocationResource;
use App\Models\StockAllocation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockAllocationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // Satu kartu tanpa judul: PO Awal − unit yang tertutup transfer = Sisa PO
                Section::make()
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema([
                        TextEntry::make('transfer_qty')
                            ->label('Saran Transfer')
                            ->formatStateUsing(fn ($state): string => StockAllocationResource::units($state))
                            ->size('lg')
                            ->weight('bold')
                            ->belowContent(fn (StockAllocation $record): string => $record->transfer_count.' transfer'),

                        TextEntry::make('po_qty_before')
                            ->label('PO Awal')
                            ->formatStateUsing(fn ($state): string => StockAllocationResource::units($state))
                            ->size('lg')
                            ->weight('bold'),

                        TextEntry::make('po_qty_after')
                            ->label('Sisa PO')
                            ->formatStateUsing(fn ($state): string => StockAllocationResource::units($state))
                            ->size('lg')
                            ->weight('bold')
                            ->belowContent(fn (StockAllocation $record): ?string => $record->poReductionPercent() > 0
                                ? 'turun '.$record->poReductionPercent().'%'
                                : null),
                    ]),
            ]);
    }
}

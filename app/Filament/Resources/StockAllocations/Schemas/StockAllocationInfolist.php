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
                // Kebutuhan PO Awal − unit yang tertutup transfer = Sisa PO
                Section::make('Ringkasan')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                    ->schema([
                        TextEntry::make('transfer_qty')
                            ->label('Saran Transfer')
                            ->formatStateUsing(fn ($state): string => StockAllocationResource::units($state))
                            ->size('lg')
                            ->weight('bold')
                            ->belowContent(fn (StockAllocation $record): string => $record->transfer_count.' transfer antar gudang'),

                        TextEntry::make('po_qty_before')
                            ->label('Kebutuhan PO Awal')
                            ->formatStateUsing(fn ($state): string => StockAllocationResource::units($state))
                            ->size('lg')
                            ->weight('bold')
                            ->belowContent('sebelum transfer'),

                        TextEntry::make('po_qty_after')
                            ->label('Sisa PO')
                            ->formatStateUsing(fn ($state): string => StockAllocationResource::units($state))
                            ->size('lg')
                            ->weight('bold')
                            ->belowContent(fn (StockAllocation $record): string => $record->poReductionPercent() > 0
                                ? 'turun '.$record->poReductionPercent().'% berkat transfer'
                                : 'setelah transfer'),

                        TextEntry::make('target_dos')
                            ->label('Target DOS')
                            ->formatStateUsing(fn ($state): string => $state.' hari')
                            ->size('lg')
                            ->weight('bold')
                            ->belowContent(fn (StockAllocation $record): string => 'min. transfer '.StockAllocationResource::units($record->min_transfer_qty)
                                .' · '.$record->warehouse_count.' gudang · '.$record->sku_count.' SKU'),
                    ]),
            ]);
    }
}

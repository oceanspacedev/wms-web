<?php

namespace App\Filament\Resources\FreightInvoices\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FreightInvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ringkasan Audit Faktur Ekspedisi')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('invoice_number')
                                    ->label('Nomor Faktur')
                                    ->weight('bold'),

                                TextEntry::make('expedition.name')
                                    ->label('Pihak Ekspedisi')
                                    ->badge()
                                    ->color('info'),

                                TextEntry::make('invoice_date')
                                    ->label('Tanggal Faktur')
                                    ->date('d F Y'),

                                TextEntry::make('status')
                                    ->label('Status Audit')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'approved' => 'success',
                                        'audited' => 'primary',
                                        'processing' => 'info',
                                        'disputed' => 'danger',
                                        'paid' => 'gray',
                                        default => 'warning',
                                    }),
                            ]),

                        Grid::make(3)
                            ->schema([
                                TextEntry::make('total_billed_amount')
                                    ->label('Total Ditagih Ekspedisi')
                                    ->money('IDR', locale: 'id')
                                    ->size('lg'),

                                TextEntry::make('total_approved_amount')
                                    ->label('Total Sesuai Tarif Kontrak')
                                    ->money('IDR', locale: 'id')
                                    ->color('success')
                                    ->size('lg')
                                    ->weight('bold'),

                                TextEntry::make('total_discrepancy_amount')
                                    ->label('Potongan / Koreksi Tagihan')
                                    ->money('IDR', locale: 'id')
                                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray')
                                    ->size('lg')
                                    ->weight('bold'),
                            ]),

                        Grid::make(5)
                            ->schema([
                                TextEntry::make('total_items_count')
                                    ->label('Total Resi Ditagih')
                                    ->numeric(),

                                TextEntry::make('matched_count')
                                    ->label('Sesuai Tarif Kontrak')
                                    ->numeric()
                                    ->color('success'),

                                TextEntry::make('discrepancy_count')
                                    ->label('Perlu Dicek (Tarif/Berat)')
                                    ->numeric()
                                    ->color(fn ($state) => (int) $state > 0 ? 'warning' : 'gray'),

                                TextEntry::make('unrecognized_count')
                                    ->label('Tidak Ada di Data Pengiriman')
                                    ->numeric()
                                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : 'gray'),

                                TextEntry::make('duplicate_count')
                                    ->label('Tagihan Dobel')
                                    ->numeric()
                                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : 'gray'),
                            ]),
                    ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\FreightInvoices\RelationManagers;

use App\Models\FreightInvoiceItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Hasil cek tiap baris invoice ekspedisi terhadap Data Pengiriman dan Tarif Kontrak (kolom "CEK TARIF").
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Cek Tarif per Resi';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('no_resi_awb')
            ->defaultSort('id')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('no_resi_awb')
                    ->label('No Resi')
                    ->description(fn (FreightInvoiceItem $record): ?string => $record->no_sj ? 'SJ '.$record->no_sj : null)
                    ->searchable(['no_resi_awb', 'no_sj'])
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('destination_city')
                    ->label('Rute')
                    ->formatStateUsing(fn (?string $state, FreightInvoiceItem $record): string => ($record->origin_depo ?: '-').' → '.($state ?: '-'))
                    ->description(fn (FreightInvoiceItem $record): ?string => $record->service_type),
                TextColumn::make('billed_weight')
                    ->label('Berat Ditagih')
                    ->formatStateUsing(fn ($state): string => $this->kg($state).' kg')
                    ->description(fn (FreightInvoiceItem $record): ?string => $record->actual_weight !== null ? 'Data pengiriman '.$this->kg($record->actual_weight).' kg' : null),
                TextColumn::make('billed_rate')
                    ->label('Tarif /kg')
                    ->formatStateUsing(fn ($state): string => 'Rp '.number_format((float) $state, 0, ',', '.'))
                    ->description(fn (FreightInvoiceItem $record): string => (float) $record->agreed_rate > 0
                        ? 'Kontrak Rp '.number_format((float) $record->agreed_rate, 0, ',', '.')
                        : 'Kontrak -'),
                TextColumn::make('billed_total')
                    ->label('Total Ditagih')
                    ->money('IDR', locale: 'id'),
                TextColumn::make('discrepancy_amount')
                    ->label('Selisih')
                    ->money('IDR', locale: 'id')
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('audit_status')
                    ->label('CEK TARIF')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FreightInvoiceItem::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => FreightInvoiceItem::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('audit_notes')
                    ->label('Catatan')
                    ->placeholder('-')
                    ->wrap()
                    ->limit(140)
                    ->tooltip(fn (FreightInvoiceItem $record): ?string => $record->audit_notes),
            ])
            ->filters([
                SelectFilter::make('audit_status')
                    ->label('CEK TARIF')
                    ->options(FreightInvoiceItem::STATUS_LABELS),
            ]);
    }

    private function kg(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }
}

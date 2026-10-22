<?php

namespace App\Filament\Resources\StockAllocations\RelationManagers;

use App\Filament\Resources\StockAllocations\StockAllocationResource;
use App\Models\StockAllocation;
use App\Models\StockAllocationLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Posisi stok tiap gudang × SKU: DOS sebelum → sesudah transfer dan sisa PO.
 *
 * @property StockAllocation $ownerRecord
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Per Gudang';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_name')
            // Judul sudah ada di tab, jadi tidak diulang di atas tabel
            ->heading(null)
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('region')->orderBy('warehouse')->orderBy('product_name'))
            ->paginated([25, 50, 100])
            ->columns([
                // Region & kode SKU muncul saat kursor diarahkan, supaya tabel tidak penuh teks
                TextColumn::make('warehouse')
                    ->label('Gudang')
                    ->weight('bold')
                    ->tooltip(fn (StockAllocationLine $record): string => $record->region)
                    ->sortable(),
                TextColumn::make('product_name')
                    ->label('Produk')
                    ->tooltip(fn (StockAllocationLine $record): string => $record->sku)
                    ->searchable(['product_name', 'sku'])
                    ->wrap(),
                TextColumn::make('stock')
                    ->label('Stok')
                    ->numeric(locale: 'id')
                    ->alignment(Alignment::End)
                    ->description(fn (StockAllocationLine $record): ?string => $record->incoming > 0 ? '+'.$record->incoming.' incoming' : null)
                    ->sortable(),
                TextColumn::make('avg_daily_sales')
                    ->label('Laku/hari')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 1, ',', '.'))
                    ->alignment(Alignment::End)
                    ->sortable(),
                TextColumn::make('dos_before')
                    ->label('DOS')
                    ->state(fn (StockAllocationLine $record): string => $record->dos_before === null
                        ? '–'
                        : StockAllocationResource::days($record->dos_before).' → '.StockAllocationResource::days($record->dos_after))
                    ->sortable(['dos_before']),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StockAllocationLine $record): string => $record->statusLabel())
                    ->color(fn (StockAllocationLine $record): string => $record->statusColor()),
                TextColumn::make('transfer')
                    ->label('Transfer')
                    ->state(fn (StockAllocationLine $record): string => match (true) {
                        $record->transfer_in > 0 && $record->transfer_out > 0 => '+'.$record->transfer_in.' / −'.$record->transfer_out,
                        $record->transfer_in > 0 => '+'.$record->transfer_in,
                        $record->transfer_out > 0 => '−'.$record->transfer_out,
                        default => '–',
                    })
                    ->alignment(Alignment::End),
                TextColumn::make('po_qty')
                    ->label('Sisa PO')
                    ->formatStateUsing(fn ($state): string => (int) $state > 0 ? number_format((int) $state, 0, ',', '.') : '–')
                    ->weight('bold')
                    ->alignment(Alignment::End)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('region')
                    ->label('Region')
                    ->options(fn (): array => $this->distinct('region')),
                SelectFilter::make('warehouse')
                    ->label('Gudang')
                    ->options(fn (): array => $this->distinct('warehouse')),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StockAllocationLine::STATUS_LABELS),
                Filter::make('needs_po')
                    ->label('Perlu PO')
                    ->query(fn (Builder $query): Builder => $query->where('po_qty', '>', 0)),
                Filter::make('has_transfer')
                    ->label('Ada transfer')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('transfer_in', '>', 0)->orWhere('transfer_out', '>', 0))),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function distinct(string $column): array
    {
        return $this->ownerRecord->lines()->distinct()->orderBy($column)->pluck($column, $column)->all();
    }
}

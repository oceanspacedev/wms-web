<?php

namespace App\Filament\Resources\StockAllocations\RelationManagers;

use App\Filament\Resources\StockAllocations\StockAllocationResource;
use App\Models\StockAllocation;
use App\Models\StockAllocationLine;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
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

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedBuildingStorefront;

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['transfersIn', 'transfersOut']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('region')->orderBy('warehouse')->orderBy('product_name'))
            ->paginated([25, 50, 100])
            ->columns([
                // Region & kode SKU muncul saat kursor diarahkan, supaya tabel tidak penuh teks
                TextColumn::make('warehouse')
                    ->label('Gudang')
                    ->weight('medium')
                    ->tooltip(fn (StockAllocationLine $record): string => $record->region)
                    ->sortable(),
                TextColumn::make('product_name')
                    ->label('Produk')
                    ->tooltip(fn (StockAllocationLine $record): string => $record->sku)
                    ->searchable(['product_name', 'sku', 'brand'])
                    ->wrap(),
                TextColumn::make('stock')
                    ->label('Stok')
                    ->numeric(locale: 'id')
                    ->alignment(Alignment::End)
                    ->description(fn (StockAllocationLine $record): ?string => $record->incoming > 0 ? '+'.$record->incoming.' incoming' : null)
                    ->tooltip(fn (StockAllocationLine $record): string => 'Stok fisik gudang: '.number_format((float) $record->stock, 0, ',', '.').' unit'.($record->incoming > 0 ? ' (+'.number_format((float) $record->incoming, 0, ',', '.').' incoming di jalan)' : ''))
                    ->sortable(),
                TextColumn::make('avg_daily_sales')
                    ->label('Laku / hari')
                    ->state(function (StockAllocationLine $record): string {
                        $val = (float) $record->avg_daily_sales;
                        if ($val <= 0) {
                            return '0';
                        }
                        if ($val < 1 && round($val) === 0.0) {
                            return '< 1';
                        }

                        return number_format(round($val), 0, ',', '.');
                    })
                    ->tooltip(function (StockAllocationLine $record): string {
                        $val = (float) $record->avg_daily_sales;
                        if ($val <= 0) {
                            return 'Tidak ada penjualan di periode ini';
                        }

                        return 'Rata-rata asli: '.number_format($val, 2, ',', '.')." unit/hari\n(total ".number_format((float) $record->sales_qty, 0, ',', '.').' unit dalam '.$record->sales_days.' hari)';
                    })
                    ->alignment(Alignment::End)
                    ->sortable(),
                TextColumn::make('dos_before')
                    // DOS dalam bahasa biasa: stok cukup untuk berapa hari, sebelum → sesudah transfer
                    ->label('Ketahanan')
                    ->state(function (StockAllocationLine $record): string {
                        if ($record->dos_before === null) {
                            return '–';
                        }
                        $before = StockAllocationResource::days($record->dos_before);
                        $after = StockAllocationResource::days($record->dos_after);

                        return $before === $after ? $before.' hari' : $before.' → '.$after.' hari';
                    })
                    ->tooltip(function (StockAllocationLine $record): ?string {
                        if ($record->dos_before === null || (float) $record->avg_daily_sales <= 0) {
                            return 'Barang tidak laku di gudang ini (penjualan 0)';
                        }
                        $number = fn (float $value): string => number_format($value, 0, ',', '.');
                        $stok = (float) $record->stock;
                        $laku = (float) $record->avg_daily_sales;
                        $hariBefore = StockAllocationResource::days($record->dos_before);
                        $hariAfter = StockAllocationResource::days($record->dos_after);
                        $target = $record->effectiveTargetDos($this->ownerRecord->target_dos);

                        $text = "Target DOS: {$target} hari.\nSebelum: stok {$number($stok)} ÷ laku ".number_format($laku, 1, ',', '.')."/hari = cukup {$hariBefore} hari.";
                        if ($record->transfer_in > 0 || $record->transfer_out > 0) {
                            $text .= "\nSesudah mutasi: stok cukup {$hariAfter} hari.";
                        }

                        return $text;
                    })
                    ->sortable(['dos_before']),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StockAllocationLine $record): string => match ($record->status) {
                        'critical' => 'Kritis',
                        'warning' => 'Waspada',
                        'healthy' => 'Aman',
                        'overstock' => 'Berlebih',
                        'no_sales' => 'Tanpa penjualan',
                        default => $record->statusLabel(),
                    })
                    ->color(fn (StockAllocationLine $record): string => match ($record->status) {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    })
                    ->tooltip(function (StockAllocationLine $record): string {
                        $target = $record->effectiveTargetDos($this->ownerRecord->target_dos);

                        return match ($record->status) {
                            'critical' => 'Stok kritis (cukup ≤ 7 hari), segera isi ulang!',
                            'warning' => "Stok waspada (di bawah target buffer {$target} hari)",
                            'healthy' => "Stok aman dan cukup untuk target {$target} hari",
                            'overstock' => "Stok berlebih (> {$target} hari), dapat dipindahkan ke cabang yang kekurangan",
                            'no_sales' => 'Tidak ada penjualan di gudang ini',
                            default => '',
                        };
                    }),
                TextColumn::make('transfer')
                    ->label('Alokasi')
                    ->weight('medium')
                    ->state(fn (StockAllocationLine $record): string => match (true) {
                        $record->transfer_in > 0 && $record->transfer_out > 0 => '+'.number_format($record->transfer_in, 0, ',', '.').' / −'.number_format($record->transfer_out, 0, ',', '.'),
                        $record->transfer_in > 0 => 'Masuk +'.number_format($record->transfer_in, 0, ',', '.'),
                        $record->transfer_out > 0 => 'Kirim −'.number_format($record->transfer_out, 0, ',', '.'),
                        default => '–',
                    })
                    ->color(fn (StockAllocationLine $record): string => match (true) {
                        $record->transfer_in > 0 => 'success',
                        $record->transfer_out > 0 => 'gray',
                        default => 'gray',
                    })
                    ->tooltip(function (StockAllocationLine $record): ?string {
                        $parts = [];
                        if ($record->transfer_in > 0) {
                            $sources = $record->transfersIn->map(fn ($t): string => "• {$t->from_warehouse}: ".number_format($t->qty, 0, ',', '.').' unit')->join("\n");
                            $parts[] = 'Terima transfer masuk '.number_format($record->transfer_in, 0, ',', '.')." unit dari:\n".($sources ?: '• (gudang cabang)');
                        }
                        if ($record->transfer_out > 0) {
                            $destinations = $record->transfersOut->map(fn ($t): string => "• {$t->to_warehouse}: ".number_format($t->qty, 0, ',', '.').' unit')->join("\n");
                            $parts[] = 'Kirim transfer keluar '.number_format($record->transfer_out, 0, ',', '.')." unit ke:\n".($destinations ?: '• (gudang cabang)');
                        }

                        return ! empty($parts) ? implode("\n\n", $parts) : 'Tidak ada rekomendasi transfer untuk produk ini di gudang ini';
                    })
                    ->alignment(Alignment::End),
                TextColumn::make('po_qty')
                    ->label('Perlu PO')
                    ->state(fn (StockAllocationLine $record): string => (int) $record->po_qty > 0 ? number_format((int) $record->po_qty, 0, ',', '.').' unit' : '–')
                    ->weight('medium')
                    ->color(fn (StockAllocationLine $record): string => (int) $record->po_qty > 0 ? 'danger' : 'gray')
                    ->tooltip(fn (StockAllocationLine $record): ?string => (int) $record->po_qty > 0
                        ? 'Kekurangan '.number_format((int) $record->po_qty, 0, ',', '.').' unit tidak bisa ditutup transfer antar-gudang. Harus order (PO) baru ke supplier/pabrik.'
                        : 'Tidak memerlukan order tambahan ke supplier')
                    ->alignment(Alignment::End)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('brand')
                    ->label('Brand')
                    ->options(fn (): array => $this->ownerRecord->lines()->whereNotNull('brand')->where('brand', '!=', '')->distinct()->orderBy('brand')->pluck('brand', 'brand')->all()),
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

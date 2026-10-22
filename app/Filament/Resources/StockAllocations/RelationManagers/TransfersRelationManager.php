<?php

namespace App\Filament\Resources\StockAllocations\RelationManagers;

use App\Filament\Resources\StockAllocations\StockAllocationResource;
use App\Models\StockAllocation;
use App\Models\StockAllocationLine;
use App\Models\StockAllocationTransfer;
use App\Services\StockAllocationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Saran pindah stok antar gudang; admin bisa setujui, ubah qty, atau tolak.
 *
 * @property StockAllocation $ownerRecord
 */
class TransfersRelationManager extends RelationManager
{
    protected static string $relationship = 'transfers';

    protected static ?string $title = 'Saran Transfer';

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['fromLine', 'toLine', 'mutation']))
            ->defaultSort('qty', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                // Teks seperlunya: kode SKU & region muncul saat kursor diarahkan ke nama produk
                TextColumn::make('product_name')
                    ->label('Produk')
                    ->tooltip(fn (StockAllocationTransfer $record): string => $record->sku.' · '.$record->region)
                    ->searchable(['product_name', 'sku'])
                    ->wrap(),
                TextColumn::make('from_warehouse')
                    ->label('Dari')
                    ->weight('bold')
                    ->description(fn (StockAllocationTransfer $record): string => $this->lineChange($record->fromLine)),
                TextColumn::make('to_warehouse')
                    ->label('Ke')
                    ->weight('bold')
                    ->description(fn (StockAllocationTransfer $record): string => $this->lineChange($record->toLine)),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->numeric(locale: 'id')
                    ->weight('bold')
                    ->alignment(Alignment::End)
                    ->description(fn (StockAllocationTransfer $record): ?string => $record->qty !== $record->suggested_qty
                        ? 'saran '.$record->suggested_qty
                        : null)
                    ->sortable(),
                // Saran yang belum diputuskan cukup teks abu-abu; badge hanya untuk yang sudah disetujui/ditolak
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(fn (string $state): bool => $state !== StockAllocationTransfer::STATUS_PENDING)
                    ->formatStateUsing(fn (string $state): string => StockAllocationTransfer::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => StockAllocationTransfer::STATUS_COLORS[$state] ?? 'gray')
                    // Sudah masuk dokumen mutasi: nomor & statusnya, mis. "MUT-2610-001 · Dikirim"
                    ->description(fn (StockAllocationTransfer $record): ?string => $record->mutation
                        ? $record->mutation->number.' · '.$record->mutation->statusLabel()
                        : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StockAllocationTransfer::STATUS_LABELS),
                SelectFilter::make('region')
                    ->label('Region')
                    ->options(fn (): array => $this->distinct('region')),
                SelectFilter::make('from_warehouse')
                    ->label('Dari Gudang')
                    ->options(fn (): array => $this->distinct('from_warehouse')),
                SelectFilter::make('to_warehouse')
                    ->label('Ke Gudang')
                    ->options(fn (): array => $this->distinct('to_warehouse')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('approve')
                        ->label('Setujui')
                        ->icon('heroicon-o-check')
                        ->visible(fn (StockAllocationTransfer $record): bool => $record->status !== StockAllocationTransfer::STATUS_APPROVED && $this->canDecide($record))
                        ->action(fn (StockAllocationTransfer $record) => $this->decide([$record], StockAllocationTransfer::STATUS_APPROVED)),
                    Action::make('changeQty')
                        ->label('Ubah Qty')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (StockAllocationTransfer $record): bool => $this->canDecide($record))
                        ->modalHeading(fn (StockAllocationTransfer $record): string => $record->from_warehouse.' → '.$record->to_warehouse)
                        ->modalDescription(fn (StockAllocationTransfer $record): string => $record->product_name)
                        ->modalWidth('sm')
                        ->modalSubmitActionLabel('Simpan & Setujui')
                        ->fillForm(fn (StockAllocationTransfer $record): array => ['qty' => $record->qty])
                        ->schema([
                            TextInput::make('qty')
                                ->label('Qty')
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->maxValue(fn (StockAllocationTransfer $record): int => max(1, (int) $record->fromLine?->stock))
                                ->suffix('unit')
                                ->helperText(fn (StockAllocationTransfer $record): string => 'Saran '.$record->suggested_qty.' · stok '.$record->from_warehouse.' '.(int) $record->fromLine?->stock)
                                ->required(),
                        ])
                        ->action(function (StockAllocationTransfer $record, array $data): void {
                            app(StockAllocationService::class)->changeQty($record, (int) $data['qty'], auth()->id());
                            $this->dispatch('stock-allocation-updated');
                        }),
                    Action::make('reject')
                        ->label('Tolak')
                        ->icon('heroicon-o-x-mark')
                        ->visible(fn (StockAllocationTransfer $record): bool => $record->status !== StockAllocationTransfer::STATUS_REJECTED && $this->canDecide($record))
                        ->action(fn (StockAllocationTransfer $record) => $this->decide([$record], StockAllocationTransfer::STATUS_REJECTED)),
                    Action::make('reset')
                        ->label('Batalkan Keputusan')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (StockAllocationTransfer $record): bool => $record->status !== StockAllocationTransfer::STATUS_PENDING && $this->canDecide($record))
                        ->action(fn (StockAllocationTransfer $record) => $this->decide([$record], StockAllocationTransfer::STATUS_PENDING)),
                ])
                    ->label('Aksi')
                    ->tooltip('Pilihan Aksi')
                    ->icon('heroicon-m-ellipsis-vertical'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approveSelected')
                        ->label('Setujui')
                        ->icon('heroicon-o-check')
                        ->visible(fn (): bool => $this->canDecide())
                        ->action(fn (Collection $records) => $this->decide($records, StockAllocationTransfer::STATUS_APPROVED)),
                    BulkAction::make('rejectSelected')
                        ->label('Tolak')
                        ->icon('heroicon-o-x-mark')
                        ->visible(fn (): bool => $this->canDecide())
                        ->action(fn (Collection $records) => $this->decide($records, StockAllocationTransfer::STATUS_REJECTED)),
                ]),
            ]);
    }

    /**
     * @param  iterable<StockAllocationTransfer>  $transfers
     */
    private function decide(iterable $transfers, string $status): void
    {
        app(StockAllocationService::class)->decide($this->ownerRecord, $transfers, $status, auth()->id());
        $this->dispatch('stock-allocation-updated');
    }

    private function canDecide(?StockAllocationTransfer $record = null): bool
    {
        return ! $record?->isLocked() && (auth()->user()?->can('update', $this->ownerRecord) ?? false);
    }

    private function lineChange(?StockAllocationLine $line): string
    {
        if ($line === null) {
            return '–';
        }

        // Stok sesudah = stok + semua transfer masuk − keluar untuk produk ini di gudang tsb
        $stock = 'stok '.number_format($line->stock, 0, ',', '.').' → '.number_format($line->stockAfter(), 0, ',', '.');

        return $stock.' · '.($line->dos_before === null
            ? 'tidak laku'
            : 'DOS '.StockAllocationResource::days($line->dos_before).' → '.StockAllocationResource::days($line->dos_after));
    }

    /**
     * @return array<string, string>
     */
    private function distinct(string $column): array
    {
        return $this->ownerRecord->transfers()->distinct()->orderBy($column)->pluck($column, $column)->all();
    }
}

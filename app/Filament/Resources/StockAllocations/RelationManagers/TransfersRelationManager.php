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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['fromLine', 'toLine']))
            ->defaultSort('qty', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('product_name')
                    ->label('Produk')
                    ->description(fn (StockAllocationTransfer $record): string => $record->sku.' · '.$record->region)
                    ->searchable(['product_name', 'sku'])
                    ->wrap(),
                TextColumn::make('from_warehouse')
                    ->label('Dari')
                    ->weight('bold')
                    ->description(fn (StockAllocationTransfer $record): string => $this->dosChange($record->fromLine)),
                TextColumn::make('to_warehouse')
                    ->label('Ke')
                    ->weight('bold')
                    ->description(fn (StockAllocationTransfer $record): string => $this->dosChange($record->toLine)
                        .' · laku '.number_format((float) $record->toLine?->avg_daily_sales, 1, ',', '.').'/hr'),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->numeric(locale: 'id')
                    ->weight('bold')
                    ->alignment(Alignment::End)
                    ->description(fn (StockAllocationTransfer $record): ?string => $record->qty !== $record->suggested_qty
                        ? 'saran '.$record->suggested_qty
                        : null)
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StockAllocationTransfer::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => StockAllocationTransfer::STATUS_COLORS[$state] ?? 'gray'),
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
                        ->visible(fn (StockAllocationTransfer $record): bool => $record->status !== StockAllocationTransfer::STATUS_APPROVED && $this->canDecide())
                        ->action(fn (StockAllocationTransfer $record) => $this->decide([$record], StockAllocationTransfer::STATUS_APPROVED)),
                    Action::make('changeQty')
                        ->label('Ubah Qty')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (): bool => $this->canDecide())
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
                        ->visible(fn (StockAllocationTransfer $record): bool => $record->status !== StockAllocationTransfer::STATUS_REJECTED && $this->canDecide())
                        ->action(fn (StockAllocationTransfer $record) => $this->decide([$record], StockAllocationTransfer::STATUS_REJECTED)),
                    Action::make('reset')
                        ->label('Kembalikan ke Menunggu')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (StockAllocationTransfer $record): bool => $record->status !== StockAllocationTransfer::STATUS_PENDING && $this->canDecide())
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

    private function canDecide(): bool
    {
        return auth()->user()?->can('update', $this->ownerRecord) ?? false;
    }

    private function dosChange(?StockAllocationLine $line): string
    {
        if ($line?->dos_before === null) {
            return 'tidak ada penjualan';
        }

        return 'DOS '.StockAllocationResource::days($line->dos_before).' → '.StockAllocationResource::days($line->dos_after).' hr';
    }

    /**
     * @return array<string, string>
     */
    private function distinct(string $column): array
    {
        return $this->ownerRecord->transfers()->distinct()->orderBy($column)->pluck($column, $column)->all();
    }
}

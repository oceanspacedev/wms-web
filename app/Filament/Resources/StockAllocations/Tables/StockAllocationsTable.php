<?php

namespace App\Filament\Resources\StockAllocations\Tables;

use App\Filament\Resources\StockAllocations\Actions\ExportAllocationAction;
use App\Filament\Resources\StockAllocations\StockAllocationResource;
use App\Models\StockAllocation;
use App\Models\StockAllocationTransfer;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockAllocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount([
                    'transfers as approved_count' => fn (Builder $query): Builder => $query->where('status', StockAllocationTransfer::STATUS_APPROVED),
                ])
                ->withExists('mutations'))
            ->columns([
                TextColumn::make('name')
                    ->label('Data')
                    ->searchable()
                    ->weight('bold')
                    ->wrap()
                    ->description(fn (StockAllocation $record): string => implode(' · ', [
                        $record->created_at?->format('d/m/Y H:i'),
                        $record->warehouse_count.' gudang',
                        $record->sku_count.' SKU',
                    ])),

                TextColumn::make('transfer_qty')
                    ->label('Transfer')
                    ->formatStateUsing(fn ($state): string => StockAllocationResource::units($state))
                    ->description(fn (StockAllocation $record): string => $record->transfer_count.' saran · '.$record->approved_count.' disetujui')
                    ->sortable(),

                TextColumn::make('po_qty_before')
                    ->label('PO Awal')
                    ->formatStateUsing(fn ($state): string => StockAllocationResource::units($state))
                    ->sortable(),

                TextColumn::make('po_qty_after')
                    ->label('Sisa PO')
                    ->formatStateUsing(fn ($state): string => StockAllocationResource::units($state))
                    ->description(fn (StockAllocation $record): ?string => $record->poReductionPercent() > 0 ? 'turun '.$record->poReductionPercent().'%' : null)
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label('Diupload oleh')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    ExportAllocationAction::make(),
                    // Sudah ada dokumen mutasi: tidak bisa dihapus (lihat StockAllocation::booted)
                    DeleteAction::make()
                        ->hidden(fn (StockAllocation $record): bool => (bool) $record->mutations_exists),
                ])
                    ->label('Aksi')
                    ->tooltip('Pilihan Aksi')
                    ->icon('heroicon-m-ellipsis-vertical'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Yang sudah punya dokumen mutasi ditolak per data dan dilaporkan gagal
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

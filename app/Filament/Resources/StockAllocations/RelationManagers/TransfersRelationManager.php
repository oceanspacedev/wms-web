<?php

namespace App\Filament\Resources\StockAllocations\RelationManagers;

use App\Filament\Resources\StockAllocations\StockAllocationResource;
use App\Models\StockAllocation;
use App\Models\StockAllocationLine;
use App\Models\StockAllocationTransfer;
use App\Models\StockMutation;
use App\Services\StockAllocationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

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
            // Posisi pekerjaan dalam satu baris, mis. "53 saran · 1 disetujui · 52 belum disetujui"
            ->description(fn (): string => $this->progressSummary())
            ->columns([
                // Teks seperlunya: kode SKU & region muncul saat kursor diarahkan ke nama produk
                TextColumn::make('product_name')
                    ->label('Produk')
                    ->tooltip(fn (StockAllocationTransfer $record): string => $record->sku.' · '.$record->region)
                    ->searchable(['product_name', 'sku'])
                    ->wrap(),
                // Dibaca seperti kalimat: kirim dari → ke, karena stok penerima cuma cukup sekian hari
                TextColumn::make('route')
                    ->label('Kirim')
                    ->state(fn (StockAllocationTransfer $record): string => $record->from_warehouse.' → '.$record->to_warehouse)
                    ->weight('medium')
                    ->description(fn (StockAllocationTransfer $record): string => $this->cover($record->fromLine, $record->from_warehouse).' · '.$this->cover($record->toLine, $record->to_warehouse))
                    // Alasan lengkap saat kursor diarahkan
                    ->tooltip(fn (StockAllocationTransfer $record): string => $this->reason($record)),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->numeric(locale: 'id')
                    ->weight('bold')
                    ->alignment(Alignment::End)
                    ->description(fn (StockAllocationTransfer $record): ?string => $record->qty !== $record->suggested_qty
                        ? 'saran '.$record->suggested_qty
                        : null)
                    ->sortable(),
                // Satu status saja: sudah masuk dokumen mutasi → status dokumennya, belum → keputusan saran.
                // Yang belum diputuskan cukup teks abu-abu; badge untuk yang sudah ada keputusan.
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (StockAllocationTransfer $record): string => $this->progress($record)['label'])
                    ->badge(fn (StockAllocationTransfer $record): bool => $record->status !== StockAllocationTransfer::STATUS_PENDING)
                    ->color(fn (StockAllocationTransfer $record): string => $this->progress($record)['color'])
                    ->description(fn (StockAllocationTransfer $record): ?string => $this->hasDocument($record) ? $record->mutation->number : null),
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
                                // Stok pengirim dikurangi saran lain dari gudang yang sama, supaya stoknya tidak minus
                                ->maxValue(fn (StockAllocationTransfer $record): int => max(1, app(StockAllocationService::class)->availableQty($record)))
                                ->suffix('unit')
                                ->helperText(fn (StockAllocationTransfer $record): string => 'Saran '.$record->suggested_qty.' · maks. '.app(StockAllocationService::class)->availableQty($record).' (stok '.$record->from_warehouse.' '.(int) $record->fromLine?->stock.')')
                                ->required(),
                        ])
                        ->action(fn (StockAllocationTransfer $record, array $data) => $this->run(
                            fn () => app(StockAllocationService::class)->changeQty($record, (int) $data['qty'], auth()->id()),
                        )),
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
        $this->run(fn () => app(StockAllocationService::class)->decide($this->ownerRecord, $transfers, $status, auth()->id()));
    }

    /**
     * Jalankan perubahan; yang ditolak aturan (stok tidak cukup, sudah masuk dokumen) cukup jadi notifikasi.
     */
    private function run(callable $change): void
    {
        try {
            $change();
        } catch (RuntimeException $exception) {
            Notification::make()->title('Tidak bisa disimpan')->body($exception->getMessage())->danger()->send();

            return;
        }

        $this->dispatch('stock-allocation-updated');
    }

    private function canDecide(?StockAllocationTransfer $record = null): bool
    {
        return ! $record?->isLocked() && (auth()->user()?->can('update', $this->ownerRecord) ?? false);
    }

    /**
     * Stok gudang cukup untuk berapa hari dalam bahasa biasa: "GMJKT cukup 19 hari", "GMSBY tidak laku", "GMPKU stok habis".
     */
    private function cover(?StockAllocationLine $line, string $warehouse): string
    {
        return $warehouse.' '.match (true) {
            $line === null => '–',
            $line->dos_before === null => 'tidak laku',
            $line->stock <= 0 => 'stok habis',
            // Angka ribuan hari (stok banyak, lakunya sedikit) cukup dibaca "lebih dari setahun"
            $line->dos_before > 365 => 'cukup > 1 tahun',
            default => 'cukup '.StockAllocationResource::days($line->dos_before).' hari',
        };
    }

    private function hasDocument(StockAllocationTransfer $transfer): bool
    {
        return isset(StockMutation::STATUS_LABELS[$transfer->mutation?->status]);
    }

    /**
     * @return array{label: string, color: string}
     */
    private function progress(StockAllocationTransfer $transfer): array
    {
        if ($this->hasDocument($transfer)) {
            return ['label' => $transfer->mutation->statusLabel(), 'color' => StockMutation::STATUS_COLORS[$transfer->mutation->status]];
        }

        return [
            'label' => StockAllocationTransfer::STATUS_LABELS[$transfer->status] ?? $transfer->status,
            'color' => StockAllocationTransfer::STATUS_COLORS[$transfer->status] ?? 'gray',
        ];
    }

    private function progressSummary(): string
    {
        $counts = $this->ownerRecord->transfers()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return implode(' · ', array_filter([
            $counts->sum().' saran',
            ($approved = (int) ($counts[StockAllocationTransfer::STATUS_APPROVED] ?? 0)) > 0 ? $approved.' disetujui' : null,
            ($pending = (int) ($counts[StockAllocationTransfer::STATUS_PENDING] ?? 0)) > 0 ? $pending.' belum disetujui' : null,
            ($rejected = (int) ($counts[StockAllocationTransfer::STATUS_REJECTED] ?? 0)) > 0 ? $rejected.' ditolak' : null,
        ]));
    }

    /**
     * "GMBDG laku 21/hari, stok cukup 5 hari → kurang 530 unit untuk 30 hari. GMCRB stok cukup 300 hari → lebih 810 unit."
     */
    public function reason(StockAllocationTransfer $transfer): string
    {
        $to = $transfer->toLine;
        $from = $transfer->fromLine;

        if ($to === null || $from === null) {
            return '';
        }

        $target = $this->ownerRecord->target_dos;
        // Satu desimal hanya untuk angka kecil yang memang pecahan: 2,5/hari, tapi 1/hari (bukan 1,0)
        $number = fn (float $value): string => number_format($value, $value < 10 && round($value, 1) != round($value) ? 1 : 0, ',', '.');
        // Penerima & pengirim dijelaskan sama: stok berapa, laku berapa per hari, cukup berapa hari.
        // Barang di jalan hanya ditulis untuk penerima, karena kelebihan pengirim dihitung dari stok fisik saja.
        $cover = fn (StockAllocationLine $line, bool $withIncoming = false): string => 'stok '.$number((float) $line->stock)
            .($withIncoming && $line->incoming > 0 ? ' (+'.$number($line->incoming).' di jalan)' : '')
            .', '.($line->dos_before === null
                ? 'tidak laku'
                : 'laku '.$number((float) $line->avg_daily_sales).'/hari, cukup '.StockAllocationResource::days($line->dos_before).' hari');
        $extra = max(0, (int) floor($from->stock - $target * (float) $from->avg_daily_sales));

        $hub = fn (StockAllocationLine $line): string => $line->region === StockAllocationService::HUB_REGION ? ' (pusat)' : '';

        return $to->warehouse.$hub($to).' '.$cover($to, withIncoming: true)
            .' → kurang '.$number($to->need_qty).' unit untuk '.$target.' hari. '
            .$from->warehouse.$hub($from).' '.$cover($from)
            .' → lebih '.$number($extra).' unit.';
    }

    /**
     * @return array<string, string>
     */
    private function distinct(string $column): array
    {
        return $this->ownerRecord->transfers()->distinct()->orderBy($column)->pluck($column, $column)->all();
    }
}

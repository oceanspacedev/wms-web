<?php

namespace App\Filament\Resources\StockAllocations\RelationManagers;

use App\Filament\Resources\StockAllocations\StockAllocationResource;
use App\Models\StockAllocation;
use App\Models\StockAllocationLine;
use App\Models\StockAllocationTransfer;
use App\Models\StockMutation;
use App\Services\StockAllocationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
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

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedArrowsRightLeft;

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
            ->recordAction('explain')
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
                // Dibaca seperti kalimat: kirim dari → ke; klik baris untuk membuka kotak penjelasan hitungan
                TextColumn::make('route')
                    ->label('Kirim')
                    ->state(fn (StockAllocationTransfer $record): string => $record->from_warehouse.' → '.$record->to_warehouse)
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->iconPosition(IconPosition::After)
                    ->iconColor('gray')
                    ->weight('medium')
                    ->description(fn (StockAllocationTransfer $record): string => $this->cover($record->fromLine, $record->from_warehouse).' · '.$this->cover($record->toLine, $record->to_warehouse)),
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
                    Action::make('explain')
                        ->label('Penjelasan Hitungan')
                        ->icon(Heroicon::OutlinedInformationCircle)
                        ->color('gray')
                        ->modalHeading(fn (StockAllocationTransfer $record): string => $record->from_warehouse.' → '.$record->to_warehouse)
                        ->modalDescription(fn (StockAllocationTransfer $record): string => $record->product_name.' · '.$record->sku.' · '.$record->region)
                        ->modalWidth(Width::TwoExtraLarge)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Tutup')
                        ->modalContent(fn (StockAllocationTransfer $record): View => view(
                            'filament.stock-allocations.explain-transfer',
                            $this->explanationData($record),
                        )),
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
     * "GMJKT (pusat): stok 1.632 ÷ laku 84/hari = cukup 19 hari. Target buffer 30 hari butuh 2.534 unit → kurang 902 unit.
     * GMSBY: stok 1.845 ÷ laku 13/hari = cukup 138 hari. Target buffer 30 hari butuh 403 unit → lebih 1.442 unit aman dikirim."
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
        $hub = fn (StockAllocationLine $line): string => $line->region === StockAllocationService::HUB_REGION ? ' (pusat)' : '';
        // Hitungan Penerima (Tujuan)
        $toStock = (float) $to->stock;
        $toLaku = (float) $to->avg_daily_sales;
        $toIncoming = (float) $to->incoming;
        $toEffectiveStock = $toStock + $toIncoming;
        $toTargetQty = ceil($target * $toLaku);
        $toDays = $to->dos_before !== null ? StockAllocationResource::days($to->dos_before).' hari' : 'tidak laku';

        $toText = $to->warehouse.$hub($to).': stok '.$number($toStock)
            .($toIncoming > 0 ? ' (+'.$number($toIncoming).' di jalan = '.$number($toEffectiveStock).')' : '')
            .($toLaku > 0 ? ' ÷ laku '.$number($toLaku).'/hari = cukup '.$toDays : ' (tidak laku)')
            .'. Target buffer '.$target.' hari butuh '.$number($toTargetQty).' unit → kurang '.$number($to->need_qty).' unit.';

        // Hitungan Pengirim (Asal)
        $fromStock = (float) $from->stock;
        $fromLaku = (float) $from->avg_daily_sales;
        $fromTargetQty = ceil($target * $fromLaku);
        $fromDays = $from->dos_before !== null ? ($from->dos_before > 365 ? '>1 tahun' : StockAllocationResource::days($from->dos_before).' hari') : 'tidak laku';
        $extra = max(0, (int) floor($fromStock - $target * $fromLaku));

        $fromText = $from->warehouse.$hub($from).': stok '.$number($fromStock)
            .($fromLaku > 0 ? ' ÷ laku '.$number($fromLaku).'/hari = cukup '.$fromDays : ' (tidak laku)')
            .'. Target buffer '.$target.' hari butuh '.$number($fromTargetQty).' unit → lebih '.$number($extra).' unit aman dikirim.';

        return $toText.' '.$fromText;
    }

    /**
     * Data terstruktur untuk modal "Penjelasan Hitungan" saat baris saran transfer diklik.
     *
     * @return array<string, mixed>
     */
    private function explanationData(StockAllocationTransfer $transfer): array
    {
        $transfer->loadMissing([
            'fromLine.transfersOut' => fn ($query) => $query
                ->where('status', '!=', StockAllocationTransfer::STATUS_REJECTED)
                ->with('mutation')
                ->orderByDesc('qty'),
            'toLine.transfersIn' => fn ($query) => $query
                ->where('status', '!=', StockAllocationTransfer::STATUS_REJECTED)
                ->with('mutation')
                ->orderByDesc('qty'),
            'mutation',
        ]);

        $to = $transfer->toLine;
        $from = $transfer->fromLine;
        $defaultTarget = $this->ownerRecord->target_dos;
        $target = $to?->effectiveTargetDos($defaultTarget)
            ?? $from?->effectiveTargetDos($defaultTarget)
            ?? $defaultTarget;
        $fromTarget = $from?->effectiveTargetDos($defaultTarget) ?? $target;
        $toTarget = $to?->effectiveTargetDos($defaultTarget) ?? $target;
        $progress = $this->progress($transfer);

        $number = fn (int|float $value): string => number_format(
            (float) $value,
            $value < 10 && round((float) $value, 1) != round((float) $value) ? 1 : 0,
            ',',
            '.',
        );
        $units = fn (int|float $value): string => number_format((float) $value, 0, ',', '.').' unit';
        $hub = fn (StockAllocationLine $line): string => $line->region === StockAllocationService::HUB_REGION ? ' (Pusat)' : '';
        $formatDays = fn (?float $dos): string => match (true) {
            $dos === null => 'Tidak laku',
            $dos > 365 => '> 1 tahun',
            default => StockAllocationResource::days($dos).' hari',
        };

        $data = [
            'qtyLabel' => 'Kirim '.$units($transfer->qty),
            'suggestedDiffNote' => $transfer->qty !== $transfer->suggested_qty
                ? 'saran '.$units($transfer->suggested_qty)
                : null,
            'targetDos' => $target,
            'statusLabel' => $progress['label'],
            'statusColor' => $progress['color'],
            'mutationNumber' => $this->hasDocument($transfer) ? $transfer->mutation->number : null,
            'sourceInfo' => null,
            'from' => null,
            'to' => null,
        ];

        if ($to === null || $from === null) {
            return $data;
        }

        $fromStock = (float) $from->stock;
        $fromLaku = (float) $from->avg_daily_sales;
        $fromTargetQty = (int) ceil($fromTarget * $fromLaku);
        $extra = max(0, (int) floor($fromStock - $fromTarget * $fromLaku));

        $toStock = (float) $to->stock;
        $toIncoming = (float) $to->incoming;
        $toLaku = (float) $to->avg_daily_sales;
        $toTargetQty = (int) ceil($toTarget * $toLaku);

        $salesPrefix = $this->ownerRecord->usesStockReport() ? 'Penjualan CSA' : 'Total terjual';
        $salesLabel = fn (StockAllocationLine $line): string => $line->sales_days > 0
            ? $salesPrefix.' ('.$line->sales_days.' hari)'
            : $salesPrefix;
        $dailySales = fn (StockAllocationLine $line): string => (float) $line->avg_daily_sales > 0
            ? $number((float) $line->avg_daily_sales).' / hari'
            : '0 / hari';
        $dosTransition = function (StockAllocationLine $line, bool $isEmpty = false) use ($formatDays): string {
            if ($line->dos_before === null) {
                return 'Tidak laku';
            }

            $before = $isEmpty ? 'Habis' : $formatDays($line->dos_before);
            $after = $formatDays($line->dos_after);

            if ($before === $after) {
                return $before;
            }

            return str_replace(' hari', '', $before).' → '.$after;
        };

        $data['sourceInfo'] = $this->ownerRecord->usesStockReport()
            ? implode(' · ', array_filter([
                'Stok per '.$this->ownerRecord->stock_date->format('d/m/Y'),
                $this->ownerRecord->sales_start && $this->ownerRecord->sales_end
                    ? 'Penjualan CSA '.$this->ownerRecord->sales_start->format('d/m').'–'.$this->ownerRecord->sales_end->format('d/m/Y').' ('.((int) $this->ownerRecord->sales_start->diffInDays($this->ownerRecord->sales_end) + 1).' hari)'
                    : null,
            ]))
            : null;

        $mapTracking = fn (iterable $transfers, string $warehouseField) => collect($transfers)
            ->map(function (StockAllocationTransfer $item) use ($transfer, $warehouseField, $units): array {
                $itemProgress = $this->progress($item);
                $trackingMeta = implode(' · ', array_filter([
                    $this->hasDocument($item) ? $item->mutation->number : null,
                    $itemProgress['label'],
                ]));

                return [
                    'warehouse' => (string) $item->{$warehouseField},
                    'qty' => $units($item->qty),
                    'meta' => $trackingMeta,
                    'color' => $itemProgress['color'],
                    'isCurrent' => $item->id === $transfer->id,
                ];
            })
            ->values()
            ->all();

        $outgoingItems = $mapTracking($from->transfersOut, 'to_warehouse');
        $incomingItems = $mapTracking($to->transfersIn, 'from_warehouse');

        $data['from'] = [
            'name' => $from->warehouse.$hub($from),
            'badge' => $fromLaku > 0 ? 'Stok Berlebih' : 'Tidak Laku',
            'stock' => $units($fromStock),
            'incomingNote' => $from->incoming > 0 ? '+'.$units($from->incoming).' (diabaikan)' : null,
            'salesLabel' => $salesLabel($from),
            'salesQty' => $units($from->sales_qty),
            'dailySales' => $dailySales($from),
            'bufferQty' => $units($fromTargetQty),
            'surplusQty' => '+'.$units($extra),
            'surplusCaption' => $fromLaku > 0 ? 'aman dikirim di atas buffer '.$fromTarget.' hari' : 'tidak ada penjualan, aman dipindahkan',
            'dosTransition' => $dosTransition($from),
            'stockAfter' => $units($from->stockAfter()),
            'transfersTitle' => 'Dikirim ke',
            'transfersSummary' => count($outgoingItems).' gudang · '.$units($from->transfer_out),
            'transfers' => $outgoingItems,
        ];

        $data['to'] = [
            'name' => $to->warehouse.$hub($to),
            'stock' => $units($toStock),
            'incomingNote' => $toIncoming > 0 ? '+'.$units($toIncoming) : null,
            'salesLabel' => $salesLabel($to),
            'salesQty' => $units($to->sales_qty),
            'dailySales' => $dailySales($to),
            'bufferQty' => $units($toTargetQty),
            'needQty' => '−'.$units($to->need_qty),
            'dosTransition' => $dosTransition($to, $toStock <= 0 && $toIncoming <= 0),
            'stockAfter' => $units($to->stockAfter() + $toIncoming),
            'remainingPo' => $to->po_qty > 0 ? $units($to->po_qty) : null,
            'transfersTitle' => 'Diterima dari',
            'transfersSummary' => count($incomingItems).' gudang · '.$units($to->transfer_in),
            'transfers' => $incomingItems,
        ];

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function distinct(string $column): array
    {
        return $this->ownerRecord->transfers()->distinct()->orderBy($column)->pluck($column, $column)->all();
    }
}

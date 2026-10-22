<?php

namespace App\Filament\Resources\StockAllocations\RelationManagers;

use App\Models\StockAllocation;
use App\Models\StockMutation;
use App\Models\StockMutationItem;
use App\Services\StockMutationExporter;
use App\Services\StockMutationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dokumen mutasi antar gudang dari saran yang disetujui: Disiapkan → Dikirim → Diterima.
 *
 * @property StockAllocation $ownerRecord
 */
class MutationsRelationManager extends RelationManager
{
    protected static string $relationship = 'mutations';

    protected static ?string $title = 'Dokumen Mutasi';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            // Judul sudah ada di tab, jadi tidak diulang di atas tabel
            ->heading(null)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('items')->withCount('items')->withSum('items', 'qty'))
            ->defaultSort('number')
            ->emptyStateHeading('Belum ada dokumen mutasi')
            ->emptyStateDescription('Setujui saran transfer, lalu pilih Aksi › Buat Dokumen Mutasi.')
            ->columns([
                TextColumn::make('number')
                    ->label('No Dokumen')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (StockMutation $record): string => $record->from_warehouse.' → '.$record->to_warehouse),
                TextColumn::make('items_sum_qty')
                    ->label('Isi')
                    ->formatStateUsing(fn ($state): string => number_format((int) $state, 0, ',', '.').' unit')
                    ->description(fn (StockMutation $record): string => $record->items_count.' SKU')
                    ->alignment(Alignment::End),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StockMutation $record): string => $record->hasDifference() ? 'Diterima, ada selisih' : $record->statusLabel())
                    ->color(fn (StockMutation $record): string => $record->hasDifference() ? 'danger' : (StockMutation::STATUS_COLORS[$record->status] ?? 'gray'))
                    ->description(fn (StockMutation $record): ?string => match ($record->status) {
                        StockMutation::STATUS_SHIPPED => 'dikirim '.$record->shipped_at?->format('d/m H:i').($record->shipped_via ? ' · '.$record->shipped_via : ''),
                        StockMutation::STATUS_RECEIVED => 'diterima '.$record->received_at?->format('d/m H:i'),
                        default => null,
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StockMutation::STATUS_LABELS),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('items')
                        ->label('Lihat Isi')
                        ->icon('heroicon-o-list-bullet')
                        ->modalHeading(fn (StockMutation $record): string => $record->number.' · '.$record->from_warehouse.' → '.$record->to_warehouse)
                        ->modalContent(fn (StockMutation $record): HtmlString => $this->itemsTable($record))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Tutup')
                        ->modalWidth('3xl'),

                    Action::make('download')
                        ->label('Unduh Excel')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(fn (StockMutation $record): StreamedResponse => app(StockMutationExporter::class)->download($record)),

                    Action::make('ship')
                        ->label('Tandai Dikirim')
                        ->icon('heroicon-o-truck')
                        ->visible(fn (StockMutation $record): bool => $record->status === StockMutation::STATUS_PREPARED && $this->canManage())
                        ->modalHeading(fn (StockMutation $record): string => 'Kirim '.$record->number)
                        ->modalDescription(fn (StockMutation $record): string => $record->from_warehouse.' → '.$record->to_warehouse)
                        ->modalWidth('md')
                        ->modalSubmitActionLabel('Tandai Dikirim')
                        ->schema([
                            TextInput::make('shipped_via')
                                ->label('Dikirim lewat')
                                ->placeholder('Contoh: mobil gudang, sopir Andi'),
                        ])
                        ->action(function (StockMutation $record, array $data): void {
                            app(StockMutationService::class)->ship($record, $data['shipped_via'] ?? null, auth()->id());
                            $this->notify('Dokumen '.$record->number.' ditandai dikirim');
                        }),

                    Action::make('receive')
                        ->label('Tandai Diterima')
                        ->icon('heroicon-o-check-circle')
                        ->visible(fn (StockMutation $record): bool => $record->status === StockMutation::STATUS_SHIPPED && $this->canManage())
                        ->modalHeading(fn (StockMutation $record): string => 'Terima '.$record->number)
                        ->modalDescription('Isi jumlah yang benar-benar diterima. Default sama dengan yang dikirim.')
                        ->modalWidth('2xl')
                        ->modalSubmitActionLabel('Tandai Diterima')
                        ->fillForm(fn (StockMutation $record): array => [
                            'items' => $record->items->map(fn (StockMutationItem $item): array => [
                                'id' => $item->id,
                                'product_name' => $item->product_name,
                                'qty' => $item->qty,
                                'received_qty' => $item->qty,
                            ])->all(),
                        ])
                        ->schema([
                            Repeater::make('items')
                                ->hiddenLabel()
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->schema([
                                    Hidden::make('id'),
                                    Hidden::make('product_name'),
                                    Hidden::make('qty'),
                                    TextInput::make('received_qty')
                                        ->label(fn (Get $get): string => $get('product_name').' (dikirim '.$get('qty').')')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(0)
                                        ->suffix('unit')
                                        ->required(),
                                ]),
                            Textarea::make('received_note')
                                ->label('Catatan')
                                ->placeholder('Contoh: 2 unit dus penyok')
                                ->rows(2),
                        ])
                        ->action(function (StockMutation $record, array $data): void {
                            $received = collect($data['items'] ?? [])
                                ->mapWithKeys(fn (array $item): array => [(int) $item['id'] => (int) $item['received_qty']])
                                ->all();

                            app(StockMutationService::class)->receive($record, $received, $data['received_note'] ?? null, auth()->id());
                            $this->notify('Dokumen '.$record->number.' ditandai diterima');
                        }),

                    Action::make('cancel')
                        ->label('Batalkan Dokumen')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->visible(fn (StockMutation $record): bool => $record->status === StockMutation::STATUS_PREPARED && $this->canManage())
                        ->requiresConfirmation()
                        ->modalDescription('Saran transfer di dokumen ini bisa diubah atau disetujui ulang.')
                        ->action(function (StockMutation $record): void {
                            app(StockMutationService::class)->cancel($record);
                            $this->notify('Dokumen '.$record->number.' dibatalkan');
                        }),
                ])
                    ->label('Aksi')
                    ->tooltip('Pilihan Aksi')
                    ->icon('heroicon-m-ellipsis-vertical'),
            ]);
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('update', $this->ownerRecord) ?? false;
    }

    private function notify(string $title): void
    {
        Notification::make()->title($title)->success()->send();
        $this->dispatch('stock-allocation-updated');
    }

    /**
     * Daftar isi dokumen untuk modal "Lihat Isi" (gaya sebaris supaya tidak perlu build CSS).
     */
    private function itemsTable(StockMutation $mutation): HtmlString
    {
        $cell = 'padding:10px 12px;border-bottom:1px solid rgba(127,127,127,.18);text-align:left';
        $num = $cell.';text-align:right;font-variant-numeric:tabular-nums';
        $received = $mutation->status === StockMutation::STATUS_RECEIVED;

        $rows = $mutation->items->sortBy('product_name')->map(function (StockMutationItem $item) use ($cell, $num, $received): string {
            $diff = $received && $item->received_qty !== $item->qty ? ';color:#dc2626;font-weight:600' : '';

            return '<tr><td style="'.$cell.'">'.e($item->product_name).'<div style="opacity:.6;font-size:12px">'.e($item->sku).'</div></td>'
                .'<td style="'.$num.'">'.number_format($item->qty, 0, ',', '.').'</td>'
                .($received ? '<td style="'.$num.$diff.'">'.number_format((int) $item->received_qty, 0, ',', '.').'</td>' : '')
                .'</tr>';
        })->implode('');

        $head = '<tr><th style="'.$cell.'">Produk</th><th style="'.$num.'">Dikirim</th>'.($received ? '<th style="'.$num.'">Diterima</th>' : '').'</tr>';
        $note = $received && filled($mutation->received_note) ? '<p style="margin:12px 0 0;opacity:.75">Catatan: '.e($mutation->received_note).'</p>' : '';

        return new HtmlString('<table style="width:100%;border-collapse:collapse;font-size:14px">'.$head.$rows.'</table>'.$note);
    }
}

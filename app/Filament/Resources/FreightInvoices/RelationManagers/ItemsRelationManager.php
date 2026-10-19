<?php

namespace App\Filament\Resources\FreightInvoices\RelationManagers;

use App\Models\FreightInvoice;
use App\Models\FreightInvoiceItem;
use App\Services\FreightReconciliationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hasil cek tiap baris invoice ekspedisi terhadap Tarif Kontrak (kolom "CEK TARIF").
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
                // Catatan hasil cek muncul saat kursor diarahkan ke badge, supaya baris tidak memanjang
                TextColumn::make('audit_status')
                    ->label('CEK TARIF')
                    ->badge()
                    ->formatStateUsing(fn (FreightInvoiceItem $record): string => $record->checkLabel())
                    ->color(fn (FreightInvoiceItem $record): string => $record->checkColor())
                    ->tooltip(fn (FreightInvoiceItem $record): ?string => $this->notes($record)),
                TextColumn::make('destination_city')
                    ->label('Rute')
                    ->formatStateUsing(fn (?string $state, FreightInvoiceItem $record): string => ($record->origin_depo ?: '-').' → '.(trim(explode('-', (string) $state)[0]) ?: '-'))
                    ->description(fn (FreightInvoiceItem $record): ?string => $record->service_type)
                    ->tooltip(fn (FreightInvoiceItem $record): ?string => $record->destination_city),
                TextColumn::make('billed_weight')
                    ->label('Berat')
                    ->formatStateUsing(fn ($state): string => $this->kg($state).' kg')
                    ->description(fn (FreightInvoiceItem $record): ?string => $record->actual_weight !== null ? 'timbang '.$this->kg($record->actual_weight).' kg' : null),
                TextColumn::make('billed_rate')
                    ->label('Tarif/kg')
                    ->formatStateUsing(fn ($state): string => 'Rp '.number_format((float) $state, 0, ',', '.'))
                    ->description(fn (FreightInvoiceItem $record): string => (float) $record->agreed_rate > 0
                        ? 'kontrak Rp '.number_format((float) $record->agreed_rate, 0, ',', '.')
                        : 'kontrak -'),
                TextColumn::make('billed_total')
                    ->label('Ditagih')
                    ->money('IDR', locale: 'id', decimalPlaces: 0),
                TextColumn::make('discrepancy_amount')
                    ->label('Selisih')
                    ->state(fn (FreightInvoiceItem $record): float => $record->correctionAmount())
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->description(fn (FreightInvoiceItem $record): ?string => $record->isUnverified() ? 'belum terverifikasi' : null)
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('audit_notes')
                    ->label('Catatan')
                    ->state(fn (FreightInvoiceItem $record): ?string => $this->notes($record))
                    ->placeholder('-')
                    ->wrap()
                    ->limit(80)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('audit_status')
                    ->label('CEK TARIF')
                    ->options([...FreightInvoiceItem::STATUS_LABELS, 'manual' => 'DISETUJUI MANUAL'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'manual' => $query->whereNotNull('manual_approved_at'),
                        default => $query->whereNull('manual_approved_at')->where('audit_status', $data['value']),
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    // Kesepakatan khusus (charter, tarif khusus, dll.): resi dianggap sesuai dengan nominal yang disetujui
                    Action::make('approveManually')
                        ->label('Setujui Manual')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (FreightInvoiceItem $record): bool => ! $record->isManuallyApproved()
                            && $record->audit_status !== 'matched' && $this->canApprove())
                        ->modalHeading(fn (FreightInvoiceItem $record): string => 'Setujui Manual Resi '.$record->no_resi_awb)
                        ->modalWidth('md')
                        ->schema([
                            TextInput::make('manual_approved_amount')
                                ->label('Nominal yang disetujui')
                                ->prefix('Rp')
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->default(fn (FreightInvoiceItem $record): float => (float) $record->billed_total)
                                ->helperText('Isi sesuai kesepakatan, misal charter Rp 2.500.000. Default: sebesar tagihan.'),
                            Textarea::make('manual_note')
                                ->label('Catatan')
                                ->placeholder('Contoh: Charter 2,5 jt / tarif khusus kesepakatan Jogja')
                                ->required(),
                        ])
                        ->action(function (FreightInvoiceItem $record, array $data): void {
                            $record->update([
                                'manual_approved_amount' => $data['manual_approved_amount'],
                                'manual_note' => $data['manual_note'],
                                'manual_approved_by' => auth()->id(),
                                'manual_approved_at' => now(),
                            ]);
                            $this->refreshInvoice();

                            Notification::make()->title('Resi disetujui manual')->success()->send();
                        }),
                    Action::make('cancelManualApproval')
                        ->label('Batalkan')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('gray')
                        ->visible(fn (FreightInvoiceItem $record): bool => $record->isManuallyApproved() && $this->canApprove())
                        ->requiresConfirmation()
                        ->modalHeading('Batalkan persetujuan manual?')
                        ->action(function (FreightInvoiceItem $record): void {
                            $record->update([
                                'manual_approved_amount' => null,
                                'manual_note' => null,
                                'manual_approved_by' => null,
                                'manual_approved_at' => null,
                            ]);
                            $this->refreshInvoice();
                        }),
                ])
                    ->tooltip('Aksi')
                    ->icon('heroicon-m-ellipsis-vertical'),
            ]);
    }

    private function canApprove(): bool
    {
        return (bool) auth()->user()?->can('update', $this->getOwnerRecord());
    }

    private function refreshInvoice(): void
    {
        /** @var FreightInvoice $invoice */
        $invoice = $this->getOwnerRecord();

        app(FreightReconciliationService::class)->refreshTotals($invoice);

        // Ringkasan di atas tabel ikut diperbarui tanpa reload halaman
        $this->dispatch('freight-invoice-updated');
    }

    private function notes(FreightInvoiceItem $record): ?string
    {
        $notes = array_filter([
            $record->isManuallyApproved()
                ? 'Disetujui manual Rp '.number_format((float) $record->manual_approved_amount, 0, ',', '.').': '.$record->manual_note
                : null,
            $record->audit_notes,
        ]);

        return $notes === [] ? null : implode(' | ', $notes);
    }

    private function kg(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }
}

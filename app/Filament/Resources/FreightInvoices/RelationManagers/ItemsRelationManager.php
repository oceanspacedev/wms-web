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
 * Hasil cek tiap baris invoice ekspedisi terhadap Tarif Kontrak dan Data Pengiriman Gudang.
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
            ->heading(null)
            ->defaultSort('id')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('no_resi_awb')
                    ->label('No Resi')
                    ->description(fn (FreightInvoiceItem $record): ?string => $record->no_sj ? 'SJ '.str($record->no_sj)->limit(18) : null)
                    ->tooltip(fn (FreightInvoiceItem $record): ?string => strlen((string) $record->no_sj) > 18 ? 'SJ: '.$record->no_sj : null)
                    ->searchable(['no_resi_awb', 'no_sj', 'shipment_badan_usaha', 'shipment_reff_note', 'shipment_brand'])
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('audit_status')
                    ->label('CEK TARIF')
                    ->badge()
                    ->formatStateUsing(fn (FreightInvoiceItem $record): string => $record->checkLabel())
                    ->color(fn (FreightInvoiceItem $record): string => $record->checkColor())
                    ->tooltip(fn (FreightInvoiceItem $record): ?string => $this->notes($record)),

                TextColumn::make('shipment_match_status')
                    ->label('Pengiriman')
                    ->badge()
                    ->formatStateUsing(fn (FreightInvoiceItem $record): string => $record->shipmentMatchLabel())
                    ->color(fn (FreightInvoiceItem $record): string => $record->shipmentMatchColor())
                    ->tooltip(fn (FreightInvoiceItem $record): ?string => $record->shipmentMatchTooltip()),

                TextColumn::make('destination_city')
                    ->label('Rute')
                    ->formatStateUsing(function (?string $state, FreightInvoiceItem $record): string {
                        $destination = trim(explode('-', (string) $state)[0]) ?: '-';

                        return ($record->origin_depo ?: '-').' → '.str($destination)->limit(18);
                    })
                    ->description(fn (FreightInvoiceItem $record): ?string => implode(' · ', array_filter([
                        $record->service_type,
                        $record->shipment_badan_usaha ? str($record->shipment_badan_usaha)->limit(18)->toString() : null,
                    ])) ?: null)
                    ->tooltip(fn (FreightInvoiceItem $record): ?string => implode(' · ', array_filter([
                        ($record->origin_depo ?: '-').' → '.($record->destination_city ?: '-'),
                        $record->shipment_badan_usaha,
                        $record->shipment_reff_note,
                        $record->shipment_brand ? 'Brand: '.$record->shipment_brand : null,
                    ])) ?: null),

                TextColumn::make('billed_weight')
                    ->label('Berat & Tarif')
                    ->formatStateUsing(function ($state, FreightInvoiceItem $record): string {
                        $weight = $this->kg($state).' kg';

                        if ((float) $record->billed_rate > 0) {
                            return $weight.' × Rp '.number_format((float) $record->billed_rate, 0, ',', '.');
                        }

                        return $weight;
                    })
                    ->description(function (FreightInvoiceItem $record): string {
                        $parts = [
                            (float) $record->agreed_rate > 0
                                ? 'kontrak Rp '.number_format((float) $record->agreed_rate, 0, ',', '.')
                                : 'kontrak -',
                        ];

                        if ($record->shipment_weight !== null && abs((float) $record->billed_weight - (float) $record->shipment_weight) > 0.05) {
                            $parts[] = 'gdg '.$this->kg($record->shipment_weight).' kg';
                        } elseif ($record->actual_weight !== null && abs((float) $record->billed_weight - (float) $record->actual_weight) > 0.05) {
                            $parts[] = 'tbg '.$this->kg($record->actual_weight).' kg';
                        }

                        return implode(' · ', $parts);
                    })
                    ->tooltip(fn (FreightInvoiceItem $record): string => $this->weightRateTooltip($record)),

                TextColumn::make('billed_total')
                    ->label('Ditagih')
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignEnd()
                    ->description(fn (FreightInvoiceItem $record): ?string => (float) $record->billed_insurance > 0
                        ? '+ asuransi Rp '.number_format((float) $record->billed_insurance, 0, ',', '.')
                        : null),

                TextColumn::make('discrepancy_amount')
                    ->label('Selisih')
                    ->state(fn (FreightInvoiceItem $record): float => $record->correctionAmount())
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignEnd()
                    ->description(fn (FreightInvoiceItem $record): ?string => $this->shortReason($record))
                    ->tooltip(fn (FreightInvoiceItem $record): ?string => implode(' | ', array_filter([
                        $record->reason(),
                        $this->notes($record),
                    ])) ?: null)
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'gray'),

                TextColumn::make('shipment_badan_usaha')
                    ->label('Badan Usaha & Brand')
                    ->placeholder('-')
                    ->description(fn (FreightInvoiceItem $record): ?string => implode(' · ', array_filter([
                        $record->shipment_reff_note,
                        $record->shipment_brand,
                    ])) ?: null)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('shipment_nominal_sj')
                    ->label('Nominal SJ')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => (float) $state > 0 ? 'Rp '.number_format((float) $state, 0, ',', '.') : '-')
                    ->description(fn (FreightInvoiceItem $record): ?string => ($ratio = $record->costRatioPercent()) !== null
                        ? 'ongkir '.number_format($ratio, 2, ',', '.').'%'
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('audit_notes')
                    ->label('Catatan')
                    ->state(fn (FreightInvoiceItem $record): ?string => $this->notes($record))
                    ->placeholder('-')
                    ->limit(80)
                    ->tooltip(fn (FreightInvoiceItem $record): ?string => $this->notes($record))
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
                SelectFilter::make('shipment_match_status')
                    ->label('Data Pengiriman')
                    ->options(FreightInvoiceItem::SHIPMENT_MATCH_LABELS),
            ])
            ->recordActions([
                ActionGroup::make([
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

    private function shortReason(FreightInvoiceItem $record): ?string
    {
        if ($record->isManuallyApproved()) {
            return 'Disetujui manual';
        }

        $agreed = (float) $record->agreed_rate;

        return match ($record->audit_status) {
            'matched' => match (true) {
                str_contains((string) $record->audit_notes, 'belum ada di Tarif Kontrak') => 'Sesuai tarif DARAT',
                (float) $record->billed_rate > 0 && (float) $record->billed_rate < $agreed - 0.5 => 'Lebih murah dr kontrak',
                default => 'Sesuai kontrak',
            },
            'revisi_darat' => 'Harusnya DARAT',
            'discrepancy_rate' => 'Tarif +Rp '.number_format((float) $record->billed_rate - $agreed, 0, ',', '.').'/kg',
            'discrepancy_weight' => $record->reason(),
            'discrepancy_both' => 'Berat & tarif lebih',
            'missing_rate', 'unrecognized' => 'Tarif rute belum ada',
            'missing_weight' => 'Berat belum diisi',
            'duplicate' => 'Resi dobel',
            default => null,
        };
    }

    private function weightRateTooltip(FreightInvoiceItem $record): string
    {
        $parts = [
            'Tagihan: '.$this->kg($record->billed_weight).' kg',
        ];

        if ($record->actual_weight !== null) {
            $parts[] = 'Timbang: '.$this->kg($record->actual_weight).' kg';
        }

        if ($record->shipment_weight !== null) {
            $parts[] = 'Gudang: '.$this->kg($record->shipment_weight).' kg';
        }

        if ((float) $record->billed_rate > 0) {
            $parts[] = 'Tarif ditagih: Rp '.number_format((float) $record->billed_rate, 0, ',', '.').'/kg';
        }

        if ((float) $record->agreed_rate > 0) {
            $parts[] = 'Kontrak: Rp '.number_format((float) $record->agreed_rate, 0, ',', '.').'/kg';
        }

        return implode(' · ', $parts);
    }

    private function kg(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }
}

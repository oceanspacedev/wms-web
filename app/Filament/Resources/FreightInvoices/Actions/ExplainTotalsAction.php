<?php

namespace App\Filament\Resources\FreightInvoices\Actions;

use App\Models\FreightInvoice;
use App\Models\FreightInvoiceItem;
use Filament\Actions\Action;
use Illuminate\Contracts\View\View;

/**
 * Modal "Penjelasan Angka": arti Total Ditagih / Disetujui / Koreksi / Belum Terverifikasi sebuah invoice,
 * rincian per hasil CEK TARIF, dan tindak lanjutnya.
 */
class ExplainTotalsAction
{
    /**
     * Tindak lanjut per hasil cek (kunci: audit_status, atau "manual").
     *
     * @var array<string, string>
     */
    private const FOLLOW_UP = [
        'matched' => '–',
        'manual' => 'Sudah disetujui',
        'discrepancy_rate' => 'Minta revisi tarif',
        'discrepancy_weight' => 'Minta revisi berat',
        'discrepancy_both' => 'Minta revisi tarif & berat',
        'revisi_darat' => 'Minta tagih ulang tarif DARAT',
        'missing_rate' => 'Tambah tarif / setujui manual',
        'missing_weight' => 'Cek berat manual',
        'unrecognized' => 'Audit ulang',
        'duplicate' => 'Tolak tagihan',
    ];

    public static function make(): Action
    {
        return Action::make('explainTotals')
            ->label('Penjelasan Angka')
            ->icon('heroicon-o-information-circle')
            ->color('gray')
            ->modalHeading('Penjelasan Angka')
            ->modalDescription(fn (FreightInvoice $record): string => $record->invoice_number)
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup')
            ->modalContent(fn (FreightInvoice $record): View => view('filament.freight-invoices.explain-totals', [
                'invoice' => $record,
                'rows' => self::breakdown($record),
            ]));
    }

    /**
     * @return list<array{label: string, color: string, count: int, billed: float, approved: float, correction: float, unverified: float, followUp: string}>
     */
    public static function breakdown(FreightInvoice $invoice): array
    {
        $order = array_flip(['matched', 'manual', ...array_keys(FreightInvoiceItem::STATUS_LABELS)]);

        return $invoice->items()->get()
            ->groupBy(fn (FreightInvoiceItem $item): string => $item->isManuallyApproved() ? 'manual' : $item->audit_status)
            ->sortBy(fn ($items, string $status): int => $order[$status] ?? 99)
            ->map(function ($items, string $status): array {
                /** @var FreightInvoiceItem $first */
                $first = $items->first();

                return [
                    'label' => $first->checkLabel(),
                    'color' => $first->checkColor(),
                    'count' => $items->count(),
                    'billed' => (float) $items->sum(fn (FreightInvoiceItem $item): float => (float) $item->billed_total),
                    'approved' => (float) $items->sum(fn (FreightInvoiceItem $item): float => $item->approvedAmount()),
                    'correction' => (float) $items->sum(fn (FreightInvoiceItem $item): float => $item->correctionAmount()),
                    'unverified' => (float) $items->filter->isUnverified()->sum(fn (FreightInvoiceItem $item): float => (float) $item->billed_total),
                    'followUp' => self::FOLLOW_UP[$status] ?? '-',
                ];
            })
            ->values()
            ->all();
    }
}

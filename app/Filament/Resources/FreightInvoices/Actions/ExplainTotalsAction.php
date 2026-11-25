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
                'shipmentSummary' => self::shipmentSummary($record),
                'companyBreakdown' => self::companyBreakdown($record),
            ]));
    }

    /**
     * Ringkasan kecocokan resi dengan Data Pengiriman (csa_shipments).
     *
     * @return array{matched: int, weight_mismatch: int, expedition_mismatch: int, unmatched: int}
     */
    public static function shipmentSummary(FreightInvoice $invoice): array
    {
        $counts = $invoice->items()
            ->selectRaw('shipment_match_status, count(*) as total')
            ->groupBy('shipment_match_status')
            ->pluck('total', 'shipment_match_status');

        return [
            'matched' => (int) ($counts['matched'] ?? 0),
            'weight_mismatch' => (int) ($counts['weight_mismatch'] ?? 0),
            'expedition_mismatch' => (int) ($counts['expedition_mismatch'] ?? 0),
            'unmatched' => (int) ($counts['unmatched'] ?? 0),
        ];
    }

    /**
     * Rekap beban tagihan per Badan Usaha & Reffnote (hasil pencocokan ke Data Pengiriman).
     *
     * @return list<array{badanUsaha: string, reffNote: string, count: int, nominalSj: float, billed: float, approved: float, ratio: ?float}>
     */
    public static function companyBreakdown(FreightInvoice $invoice): array
    {
        return $invoice->items()->get()
            ->groupBy(fn (FreightInvoiceItem $item): string => ($item->shipment_badan_usaha ?: '(Belum terpetakan)').'||'.($item->shipment_reff_note ?: '-'))
            ->map(function ($items, string $key): array {
                [$bu, $reff] = explode('||', $key);
                $nominal = (float) $items->sum(fn (FreightInvoiceItem $i): float => (float) $i->shipment_nominal_sj);
                $billed = (float) $items->sum(fn (FreightInvoiceItem $i): float => (float) $i->billed_total);
                $approved = (float) $items->sum(fn (FreightInvoiceItem $i): float => $i->approvedAmount());

                return [
                    'badanUsaha' => $bu,
                    'reffNote' => $reff,
                    'count' => $items->count(),
                    'nominalSj' => $nominal,
                    'billed' => $billed,
                    'approved' => $approved,
                    'ratio' => $nominal > 0 ? round(($billed / $nominal) * 100, 2) : null,
                ];
            })
            ->sortByDesc('billed')
            ->values()
            ->all();
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

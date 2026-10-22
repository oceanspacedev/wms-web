<?php

namespace App\Filament\Resources\FreightInvoices\Pages;

use App\Filament\Resources\FreightInvoices\Actions\ExplainTotalsAction;
use App\Filament\Resources\FreightInvoices\Actions\ReauditAction;
use App\Filament\Resources\FreightInvoices\FreightInvoiceResource;
use App\Models\FreightInvoice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewFreightInvoice extends ViewRecord
{
    protected static string $resource = FreightInvoiceResource::class;

    #[On('freight-invoice-updated')]
    public function refreshSummary(): void
    {
        $this->record->refresh();
    }

    /**
     * Cukup tabel Cek Tarif per Resi: total tagihan sudah ada di daftar invoice dan Aksi › Penjelasan Angka.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRelationManagersContentComponent(),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->invoice_number;
    }

    /**
     * Nomor invoice bisa panjang, jadi tampil lebih kecil dari judul halaman biasa.
     */
    public function getHeading(): string|Htmlable
    {
        return new HtmlString('<span style="font-size:1.375rem;line-height:1.75rem;font-weight:600">'.e($this->record->invoice_number).'</span>');
    }

    /**
     * Info invoice dalam satu baris di bawah judul, supaya tidak perlu kotak "Informasi Invoice" lagi.
     */
    public function getSubheading(): ?string
    {
        return implode(' · ', array_filter([
            $this->record->expedition?->name,
            $this->record->origin_city ? 'asal '.$this->record->origin_city : 'kota asal belum dipilih',
            $this->record->invoice_date?->format('d/m/Y'),
            FreightInvoice::STATUS_LABELS[$this->record->status] ?? 'Draft',
        ]));
    }

    protected function getHeaderActions(): array
    {
        // Semua aksi dalam satu tombol dropdown supaya header tidak penuh tombol
        return [
            ActionGroup::make($this->invoiceActions())
                ->label('Aksi')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After)
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * @return array<Action>
     */
    private function invoiceActions(): array
    {
        return [
            ExplainTotalsAction::make(),

            Action::make('exportDispute')
                ->label('Download Berita Acara Selisih (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('danger')
                ->visible(fn (): bool => $this->record->total_discrepancy_amount > 0 || $this->record->total_unverified_amount > 0)
                ->action(function (): StreamedResponse {
                    $invoice = $this->record;
                    $filename = "Berita_Acara_Koreksi_Tagihan_{$invoice->invoice_number}.csv";

                    return response()->streamDownload(function () use ($invoice) {
                        $output = fopen('php://output', 'w');

                        // UTF-8 BOM for Excel compatibility
                        fwrite($output, "\xEF\xBB\xBF");

                        // Headers
                        fputcsv($output, [
                            'Nomor Resi / AWB',
                            'Nomor SJ',
                            'Depo Asal',
                            'Kota Tujuan',
                            'Layanan',
                            'Berat Tagihan (kg)',
                            'Berat Timbang (kg)',
                            'Selisih Berat (kg)',
                            'Tarif Tagihan (Rp/kg)',
                            'Tarif Kontrak PKS (Rp/kg)',
                            'Total Tagihan Ekspedisi (Rp)',
                            'Total Sesuai Kontrak (Rp)',
                            'Nilai Koreksi / Potongan (Rp)',
                            'Status Temuan',
                            'Keterangan Audit',
                        ]);

                        $items = $invoice->items()->where('audit_status', '!=', 'matched')->get();
                        foreach ($items as $item) {
                            fputcsv($output, [
                                $item->no_resi_awb,
                                $item->no_sj ?? '-',
                                $item->origin_depo ?? '-',
                                $item->destination_city ?? '-',
                                $item->service_type ?? '-',
                                $item->billed_weight,
                                $item->actual_weight ?? 0,
                                $item->weight_discrepancy,
                                $item->billed_rate,
                                $item->agreed_rate,
                                $item->billed_total,
                                $item->isUnverified() ? 'Belum terverifikasi' : $item->approvedAmount(),
                                $item->correctionAmount(),
                                $item->checkLabel(),
                                implode(' | ', array_filter([
                                    $item->isManuallyApproved() ? 'Disetujui manual: '.$item->manual_note : null,
                                    $item->audit_notes,
                                ])),
                            ]);
                        }

                        fclose($output);
                    }, $filename, [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                    ]);
                }),

            Action::make('approve')
                ->label('Setujui Pembayaran')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => in_array($this->record->status, ['audited', 'disputed']))
                ->requiresConfirmation()
                ->modalHeading('Konfirmasi Persetujuan Pembayaran')
                ->modalDescription(fn () => "Setujui tagihan faktur #{$this->record->invoice_number} sebesar Rp ".number_format($this->record->total_approved_amount, 0, ',', '.').' (Setelah dipotong selisih Rp '.number_format($this->record->total_discrepancy_amount, 0, ',', '.').')?'
                    .((float) $this->record->total_unverified_amount > 0
                        ? ' Masih ada Rp '.number_format($this->record->total_unverified_amount, 0, ',', '.').' belum terverifikasi yang tidak ikut dibayar.'
                        : ''))
                ->action(function (): void {
                    $this->record->update(['status' => 'approved']);

                    Notification::make()
                        ->title('Tagihan Disetujui')
                        ->body('Faktur telah disetujui sebesar Rp '.number_format($this->record->total_approved_amount, 0, ',', '.'))
                        ->success()
                        ->send();
                }),

            ReauditAction::make(),

            EditAction::make(),
        ];
    }
}

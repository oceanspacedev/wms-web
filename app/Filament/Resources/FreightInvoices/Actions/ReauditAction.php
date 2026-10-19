<?php

namespace App\Filament\Resources\FreightInvoices\Actions;

use App\Filament\Resources\FreightInvoices\Schemas\FreightInvoiceForm;
use App\Jobs\ProcessFreightReconciliationJob;
use App\Models\FreightInvoice;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

/**
 * Cek ulang semua resi invoice ke Tarif Kontrak; kota asal bisa diganti sekaligus.
 */
class ReauditAction
{
    public static function make(): Action
    {
        return Action::make('reAudit')
            ->label('Audit Ulang')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->modalHeading('Audit Ulang Invoice')
            ->modalDescription('Semua resi dicek ulang ke Tarif Kontrak. Persetujuan manual tetap tersimpan.')
            ->modalWidth('md')
            ->modalSubmitActionLabel('Audit Ulang')
            ->schema([
                Select::make('origin_city')
                    ->label('Kota Asal Pengiriman')
                    ->options(fn (FreightInvoice $record): array => FreightInvoiceForm::origins($record->expedition_id))
                    ->default(fn (FreightInvoice $record): ?string => $record->origin_city ?? FreightInvoiceForm::defaultOrigin($record->expedition_id))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (FreightInvoice $record, array $data): void {
                $record->update(['origin_city' => $data['origin_city']]);

                ProcessFreightReconciliationJob::dispatch($record);

                Notification::make()
                    ->title('Audit Ulang Dimulai')
                    ->body('Semua resi sedang dicek ulang ke Tarif Kontrak. Refresh halaman sebentar lagi.')
                    ->info()
                    ->send();
            });
    }
}

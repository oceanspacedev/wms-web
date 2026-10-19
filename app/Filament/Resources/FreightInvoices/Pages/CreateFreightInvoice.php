<?php

namespace App\Filament\Resources\FreightInvoices\Pages;

use App\Filament\Resources\FreightInvoices\FreightInvoiceResource;
use App\Jobs\ProcessFreightReconciliationJob;
use App\Models\FreightInvoice;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CreateFreightInvoice extends CreateRecord
{
    protected static string $resource = FreightInvoiceResource::class;

    protected static ?string $title = 'Upload Invoice Ekspedisi';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $relativePath = $data['invoice_file'];
        $fullPath = Storage::disk('local')->path($relativePath);

        /** @var FreightInvoice $record */
        $record = static::getModel()::create([
            'expedition_id' => $data['expedition_id'],
            'invoice_number' => filled($data['invoice_number'] ?? null)
                ? $data['invoice_number']
                : $this->invoiceNumberFromFileName((string) ($data['invoice_file_name'] ?? basename($relativePath))),
            'invoice_date' => now()->toDateString(),
            'file_path' => $fullPath,
            'status' => 'processing',
            'created_by' => auth()->id(),
        ]);

        // Dispatch background reconciliation job in Horizon
        ProcessFreightReconciliationJob::dispatch($record);

        Notification::make()
            ->title('Invoice sedang dicek')
            ->body('Setiap resi dicocokkan ke Data Pengiriman dan Tarif Kontrak di belakang layar. Refresh halaman ini sebentar lagi.')
            ->success()
            ->send();

        return $record;
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Upload & Cek');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Nomor invoice dari nama file (tanpa ekstensi), diberi nomor urut kalau sudah pernah dipakai.
     */
    private function invoiceNumberFromFileName(string $fileName): string
    {
        $base = trim(pathinfo($fileName, PATHINFO_FILENAME)) ?: 'Invoice '.now()->format('Y-m-d H:i');
        $number = $base;

        for ($i = 2; FreightInvoice::where('invoice_number', $number)->exists(); $i++) {
            $number = "{$base} ({$i})";
        }

        return $number;
    }
}

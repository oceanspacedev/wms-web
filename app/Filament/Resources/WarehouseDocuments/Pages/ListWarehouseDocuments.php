<?php

namespace App\Filament\Resources\WarehouseDocuments\Pages;

use App\Filament\Resources\WarehouseDocuments\Schemas\WarehouseDocumentForm;
use App\Filament\Resources\WarehouseDocuments\WarehouseDocumentResource;
use App\Jobs\SendWarehouseDocumentToArsipJob;
use App\Models\User;
use App\Models\WarehouseDocument;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Storage;

class ListWarehouseDocuments extends ListRecords
{
    protected static string $resource = WarehouseDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkUpload')
                ->label('Upload Banyak')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->visible(fn (): bool => WarehouseDocumentResource::canCreate())
                ->disabled(fn (): bool => $this->warehouseCodeMissing())
                ->modalHeading('Upload banyak Memo/DO ke SCM Arsip')
                ->modalSubmitActionLabel('Kirim semua')
                // Tanpa sticky header/footer: modal sticky menjadi area scroll sendiri dan memotong dropdown di baris bawah tabel
                ->modalWidth(Width::ThreeExtraLarge)
                ->steps(WarehouseDocumentForm::bulkSteps())
                ->action(function (array $data): void {
                    $programs = collect(WarehouseDocumentForm::availablePrograms())->keyBy(fn (array $program): string => (string) $program['id']);
                    $pathsByName = array_flip($data['file_names'] ?? []);
                    $sent = 0;

                    foreach ($data['items'] ?? [] as $item) {
                        $program = $programs->get((string) ($item['arsip_program_id'] ?? ''));
                        $path = $data['files'][$item['file_key'] ?? ''] ?? $pathsByName[$item['file_name'] ?? ''] ?? null;

                        // PO di luar hak akses pengguna (atau file yang tidak tersimpan) dilewati
                        if (! $program || ! $path) {
                            continue;
                        }

                        $this->storeAndQueue($program, $path, $data['file_names'][$path] ?? $item['file_name'] ?? basename($path));
                        $sent++;
                    }

                    Notification::make()
                        ->title("{$sent} dokumen diunggah")
                        ->body('Sedang dikirim ke SCM Arsip di belakang layar. Status di tabel akan diperbarui otomatis.')
                        ->success()
                        ->send();
                }),

            CreateAction::make()
                ->label('Upload Memo/DO')
                ->modalHeading('Upload Memo/DO ke SCM Arsip')
                ->modalSubmitActionLabel('Upload')
                ->modalWidth(Width::Large)
                ->createAnother(false)
                ->disabled(fn (): bool => $this->warehouseCodeMissing())
                ->tooltip(fn (): ?string => $this->warehouseCodeMissing() ? 'Akun Anda belum punya Kode Gudang Arsip. Hubungi admin.' : null)
                ->using(function (array $data): WarehouseDocument {
                    // Kode gudang diambil dari PO di SCM Arsip, dan hanya PO yang boleh diakses pengguna ini
                    $program = collect(WarehouseDocumentForm::availablePrograms())
                        ->first(fn (array $program): bool => (string) $program['id'] === (string) $data['arsip_program_id']);

                    if (! $program) {
                        Notification::make()
                            ->title('PO tidak bisa dipilih')
                            ->body('PO ini tidak ditemukan di SCM Arsip untuk gudang Anda. Muat ulang halaman lalu coba lagi.')
                            ->danger()
                            ->send();

                        throw new Halt;
                    }

                    return $this->storeAndQueue(
                        $program,
                        $data['file_path'],
                        $data['original_name'] ?? basename($data['file_path']),
                    );
                })
                ->successNotification(
                    Notification::make()
                        ->title('Dokumen berhasil diunggah')
                        ->body('Sedang dikirim ke SCM Arsip di belakang layar. Status di tabel akan diperbarui otomatis.')
                        ->success()
                ),
        ];
    }

    /**
     * Simpan dokumen lalu kirim ke SCM Arsip lewat antrean.
     *
     * @param  array{id: string|int, title?: string, po_sj_number?: ?string, kode_gudang: string}  $program
     */
    private function storeAndQueue(array $program, string $path, string $originalName): WarehouseDocument
    {
        $document = WarehouseDocument::create([
            'user_id' => auth()->id(),
            'kode_gudang' => $program['kode_gudang'],
            'arsip_program_id' => (string) $program['id'],
            'po_sj_number' => $program['po_sj_number'] ?? null,
            'program_title' => $program['title'] ?? null,
            'file_path' => $path,
            'original_name' => $originalName,
            'file_size' => Storage::disk('local')->size($path),
            'status' => WarehouseDocument::STATUS_PENDING,
        ]);

        SendWarehouseDocumentToArsipJob::dispatch($document);

        return $document;
    }

    private function warehouseCodeMissing(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isWarehouseStaff() && blank($user->kode_gudang_arsip);
    }
}

<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Exports\UserExporter;
use App\Filament\Resources\Users\UserResource;
use App\Jobs\SendUserAccountActivatedWhatsAppJob;
use App\Models\User;
use App\Services\TalentaCourierImporter;
use App\Services\UserSpreadsheetImporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        // Import & export dalam satu dropdown supaya header tidak penuh tombol
        return [
            ActionGroup::make([
                ExportAction::make()
                    ->exporter(UserExporter::class)
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray'),

                $this->importUsersAction(),

                $this->importTalentaCouriersAction(),
            ])
                ->label('Import / Export')
                ->icon('heroicon-m-chevron-down')
                ->iconPosition(IconPosition::After)
                ->button()
                ->color('gray'),

            CreateAction::make(),
        ];
    }

    private function importUsersAction(): Action
    {
        return Action::make('importUsers')
            ->label('Import Pengguna (Excel)')
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (): bool => auth()->user()?->can('create', User::class) ?? false)
            ->modalHeading('Import Pengguna dari Excel')
            ->modalDescription(fn (Action $action): Htmlable => $action->getModalAction('downloadTemplate'))
            ->registerModalActions([
                Action::make('downloadTemplate')
                    ->label('Download template Excel')
                    ->link()
                    ->action(fn (): StreamedResponse => app(UserSpreadsheetImporter::class)->template()),
            ])
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('File Excel (.xlsx)')
                    ->helperText('Isi kolom Nama Lengkap dan Nomor WhatsApp. Username dibuat otomatis dari nama dan email dikosongkan; nomor WhatsApp yang sudah terdaftar dilewati.')
                    ->disk('local')
                    ->directory('user-imports')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/octet-stream',
                        'application/zip',
                    ])
                    ->maxSize(10240)
                    ->required(),
                $this->sendWhatsAppToggle(),
            ])
            ->action(function (array $data): void {
                try {
                    $result = app(UserSpreadsheetImporter::class)->import(
                        Storage::disk('local')->path($data['file']),
                        (bool) ($data['send_whatsapp'] ?? true),
                    );
                } catch (RuntimeException $exception) {
                    $this->notifyUnreadableFile($exception);

                    return;
                } finally {
                    Storage::disk('local')->delete($data['file']);
                }

                $this->notifyImportResult($result, 'akun pengguna dibuat', 'Nama kosong/WhatsApp tidak valid', 'Tidak ada data pengguna di file ini.');
            });
    }

    private function importTalentaCouriersAction(): Action
    {
        return Action::make('importTalentaCouriers')
            ->label('Import Kurir Talenta')
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (): bool => auth()->user()?->can('create', User::class) ?? false)
            ->modalHeading('Import Akun Kurir dari Talenta')
            ->modalDescription('Upload JSON daftar karyawan Talenta. Hanya karyawan organisasi '.TalentaCourierImporter::ORGANIZATION.' dengan jabatan '.implode(' atau ', TalentaCourierImporter::JOB_KEYWORDS).' yang dibuatkan akun; yang email atau nomor WhatsApp-nya sudah terdaftar dilewati.')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('File JSON Talenta')
                    ->disk('local')
                    ->directory('talenta-imports')
                    ->acceptedFileTypes(['application/json', 'text/plain'])
                    ->maxSize(10240)
                    ->required(),
                $this->sendWhatsAppToggle(),
            ])
            // Daftar karyawan hanya ratusan baris, jadi diproses langsung tanpa antrean
            ->action(function (array $data): void {
                try {
                    $result = app(TalentaCourierImporter::class)->import(
                        (string) Storage::disk('local')->get($data['file']),
                        (bool) ($data['send_whatsapp'] ?? true),
                    );
                } catch (RuntimeException $exception) {
                    $this->notifyUnreadableFile($exception);

                    return;
                } finally {
                    Storage::disk('local')->delete($data['file']);
                }

                $this->notifyImportResult($result, 'akun kurir dibuat', 'Email/WhatsApp tidak valid', 'Tidak ada kurir gudang di file ini.');
            });
    }

    private function sendWhatsAppToggle(): Toggle
    {
        return Toggle::make('send_whatsapp')
            ->label('Kirim username & password via WhatsApp')
            ->helperText('Berisi link download aplikasi, cara login dengan OTP WhatsApp, serta username dan password awal. Dikirim bergiliran satu per satu dengan jeda '.implode('–', SendUserAccountActivatedWhatsAppJob::GAP_SECONDS).' detik supaya nomor WhatsApp pengirim tidak kena banned. Kalau dimatikan, password tidak diketahui siapa pun dan pengguna hanya bisa login dengan OTP.')
            ->default(true);
    }

    private function notifyUnreadableFile(RuntimeException $exception): void
    {
        Notification::make()
            ->title('File tidak bisa dibaca')
            ->body($exception->getMessage())
            ->danger()
            ->send();
    }

    /**
     * @param  array{created: list<string>, existing: list<string>, incomplete: list<string>}  $result
     */
    private function notifyImportResult(array $result, string $createdTitle, string $incompleteLabel, string $emptyBody): void
    {
        $lines = collect([
            'Dibuat' => $result['created'],
            'Sudah punya akun' => $result['existing'],
            $incompleteLabel => $result['incomplete'],
        ])
            ->filter()
            ->map(fn (array $names, string $label): string => "{$label} (".count($names).'): '.implode(', ', $names))
            ->values()
            ->all();

        Notification::make()
            ->title(count($result['created']).' '.$createdTitle)
            ->body($lines === [] ? $emptyBody : implode('<br>', array_map('e', $lines)))
            ->success()
            ->persistent()
            ->send();
    }
}

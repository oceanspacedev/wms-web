<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\TalentaCourierImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importTalentaCouriers')
                ->label('Import Kurir Talenta')
                ->color('gray')
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
                    Toggle::make('send_whatsapp')
                        ->label('Kirim username & password via WhatsApp')
                        ->helperText('Berisi link download aplikasi, cara login dengan OTP WhatsApp, serta username dan password awal. Kalau dimatikan, password tidak diketahui siapa pun dan kurir hanya bisa login dengan OTP.')
                        ->default(true),
                ])
                // Daftar karyawan hanya ratusan baris, jadi diproses langsung tanpa antrean
                ->action(function (array $data): void {
                    try {
                        $result = app(TalentaCourierImporter::class)->import(
                            (string) Storage::disk('local')->get($data['file']),
                            (bool) ($data['send_whatsapp'] ?? true),
                        );
                    } catch (RuntimeException $exception) {
                        Notification::make()
                            ->title('File tidak bisa dibaca')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($data['file']);
                    }

                    $lines = collect([
                        'Dibuat' => $result['created'],
                        'Sudah punya akun' => $result['existing'],
                        'Email/WhatsApp tidak valid' => $result['incomplete'],
                    ])
                        ->filter()
                        ->map(fn (array $names, string $label): string => "{$label} (".count($names).'): '.implode(', ', $names))
                        ->values()
                        ->all();

                    Notification::make()
                        ->title(count($result['created']).' akun kurir dibuat')
                        ->body($lines === [] ? 'Tidak ada kurir gudang di file ini.' : implode('<br>', array_map('e', $lines)))
                        ->success()
                        ->persistent()
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}

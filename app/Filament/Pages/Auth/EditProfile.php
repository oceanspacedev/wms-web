<?php

namespace App\Filament\Pages\Auth;

use App\Services\Telegram\TelegramBot;
use App\Support\MediaFileName;
use Apriansyahrs\MekayaTheme\Auth\MekayaEditProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EditProfile extends MekayaEditProfile
{
    /**
     * Kode hubungkan Telegram yang sedang ditampilkan di modal.
     */
    public ?string $telegramLinkCode = null;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('avatar_url')
                    ->label('Foto Profil')
                    ->avatar()
                    ->alignCenter()
                    ->directory('avatars')
                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file, Get $get): string => MediaFileName::make($file, auth()->user()?->username ?: $get('name'), 'avatar'))
                    ->imageEditor()
                    ->circleCropper()
                    ->maxSize(2048),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ...parent::getHeaderActions(),
            $this->connectTelegramAction(),
            $this->disconnectTelegramAction(),
        ];
    }

    /**
     * Bot Telegram hanya menjawab chat yang terhubung ke akun; hubungkan dengan kode sekali pakai lewat "/start KODE".
     */
    private function connectTelegramAction(): Action
    {
        return Action::make('connectTelegram')
            ->label('Hubungkan Telegram')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->telegram_chat_id === null)
            ->mountUsing(function (): void {
                $this->telegramLinkCode = TelegramBot::issueLinkCode(auth()->user());
            })
            ->modalHeading('Hubungkan Telegram')
            ->modalWidth(Width::Medium)
            ->modalContent(fn (): HtmlString => $this->telegramInstructions())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup')
            ->extraModalFooterActions(fn (): array => array_filter([
                ($url = TelegramBot::linkUrl((string) $this->telegramLinkCode)) !== null
                    ? Action::make('openTelegram')->label('Buka Telegram')->url($url, shouldOpenInNewTab: true)
                    : null,
            ]));
    }

    private function disconnectTelegramAction(): Action
    {
        return Action::make('disconnectTelegram')
            ->label('Telegram Terhubung')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->telegram_chat_id !== null)
            ->requiresConfirmation()
            ->modalHeading('Putuskan Telegram?')
            ->modalDescription('Bot Telegram tidak akan menjawab chat ini lagi sampai dihubungkan ulang.')
            ->modalSubmitActionLabel('Putuskan')
            ->action(function (): void {
                auth()->user()->forceFill(['telegram_chat_id' => null])->save();

                Notification::make()->title('Telegram diputus')->success()->send();
            });
    }

    private function telegramInstructions(): HtmlString
    {
        $code = e((string) $this->telegramLinkCode);
        $bot = ltrim((string) config('services.telegram.bot_username'), '@');
        $botLabel = $bot !== '' ? '<b>@'.e($bot).'</b>' : 'bot Telegram WMS';

        return new HtmlString('<div style="display:grid;gap:12px;font-size:14px;line-height:1.5">'
            .'<p>Klik <b>Buka Telegram</b> lalu tekan <b>Start</b>, atau kirim pesan ini ke '.$botLabel.':</p>'
            .'<p style="font-family:monospace;font-size:16px;padding:10px 12px;border-radius:8px;background:rgb(0 0 0 / .05)">/start '.$code.'</p>'
            .'<p style="color:rgb(107 114 128)">Kode berlaku '.TelegramBot::LINK_CODE_MINUTES.' menit. Setelah terhubung, tanya bot misalnya <i>"sj hari ini"</i>.</p>'
            .'</div>');
    }
}

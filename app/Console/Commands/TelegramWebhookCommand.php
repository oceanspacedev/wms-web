<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

/**
 * Daftarkan URL webhook bot Telegram ke server ini (jalankan sekali setelah deploy atau ganti token).
 */
class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {--delete : Hapus webhook (mis. supaya bisa tes lokal dengan telegram:poll)}';

    protected $description = 'Daftarkan (atau hapus) webhook bot Telegram ke URL aplikasi ini';

    public function handle(TelegramClient $telegram): int
    {
        if ($this->option('delete')) {
            $response = $telegram->deleteWebhook();
            $this->line($response->successful() ? 'Webhook dihapus.' : 'Gagal: '.$response->json('description'));

            return $response->successful() ? self::SUCCESS : self::FAILURE;
        }

        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET belum diisi di .env (isi teks acak, mis. dari `php -r "echo bin2hex(random_bytes(16));"`).');

            return self::FAILURE;
        }

        $url = route('telegram.webhook');

        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram hanya menerima webhook HTTPS, sedangkan APP_URL menghasilkan {$url}. Untuk tes lokal pakai telegram:poll.");

            return self::FAILURE;
        }

        $response = $telegram->setWebhook($url, $secret);
        $this->line($response->successful() ? "Webhook terdaftar: {$url}" : 'Gagal: '.$response->json('description'));

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}

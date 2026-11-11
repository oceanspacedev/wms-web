<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBot;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Jalankan bot tanpa webhook (untuk tes di laptop, karena Telegram hanya mengirim webhook ke HTTPS publik).
 * Selama bot masih punya webhook (mis. di staging), Telegram menolak cara ini: hapus dulu dengan telegram:webhook --delete.
 */
class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll';

    protected $description = 'Jalankan bot Telegram dengan long polling (tes lokal); hentikan dengan Ctrl+C';

    public function handle(TelegramClient $telegram, TelegramBot $bot): int
    {
        $this->info('Bot berjalan. Kirim pesan ke bot di Telegram; Ctrl+C untuk berhenti.');
        $offset = 0;

        while (true) {
            try {
                $updates = $telegram->getUpdates($offset);
            } catch (RuntimeException $exception) {
                // Bot masih punya webhook atau dijalankan dua kali: berhenti. Gangguan jaringan sesaat: coba lagi.
                if (str_contains($exception->getMessage(), 'Conflict') || str_contains($exception->getMessage(), 'belum diisi')) {
                    $this->error($exception->getMessage());

                    return self::FAILURE;
                }

                $this->warn($exception->getMessage().' — mencoba lagi dalam 5 detik…');
                sleep(5);

                continue;
            }

            foreach ($updates as $update) {
                $offset = (int) $update['update_id'] + 1;
                $this->line('Pesan: '.mb_substr((string) ($update['message']['text'] ?? ''), 0, 80));

                try {
                    $bot->handle($update);
                } catch (Throwable $exception) {
                    $this->error('Gagal membalas: '.$exception->getMessage());
                }
            }
        }
    }
}

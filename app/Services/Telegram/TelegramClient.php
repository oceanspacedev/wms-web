<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Panggilan ke Telegram Bot API. Token tidak pernah ikut ditulis ke log.
 */
class TelegramClient
{
    /**
     * Batas panjang satu pesan Telegram.
     */
    public const MAX_MESSAGE_LENGTH = 4096;

    public function sendMessage(int $chatId, string $html): bool
    {
        $response = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => mb_substr($html, 0, self::MAX_MESSAGE_LENGTH),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);

        if (! $response->successful()) {
            Log::warning('Gagal mengirim pesan Telegram.', ['status' => $response->status(), 'description' => $response->json('description')]);
        }

        return $response->successful();
    }

    /**
     * Status di chat, mis. "typing" (sedang mengetik…) selama jawaban disiapkan.
     */
    public function sendChatAction(int $chatId, string $action): void
    {
        $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action], 5);
    }

    /**
     * @param  list<string>  $allowedUpdates
     */
    public function setWebhook(string $url, string $secret, array $allowedUpdates = ['message']): Response
    {
        return $this->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => $allowedUpdates]);
    }

    public function deleteWebhook(): Response
    {
        return $this->call('deleteWebhook');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout = 25): array
    {
        $response = $this->call('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => ['message']], $timeout + 10);

        if (! $response->successful()) {
            throw new RuntimeException('Telegram getUpdates gagal: '.($response->json('description') ?? $response->status()));
        }

        return $response->json('result') ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, array $payload = [], int $timeout = 15): Response
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN belum diisi di .env.');
        }

        try {
            return Http::asJson()->timeout($timeout)->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (ConnectionException $exception) {
            // Pesan Guzzle memuat URL lengkap (berisi token): token disamarkan, dan exception asli sengaja
            // tidak dirantai supaya tidak ikut tercetak di log atau terminal
            throw new RuntimeException('Tidak bisa terhubung ke Telegram: '.str_replace($token, '***', $exception->getMessage()));
        }
    }
}

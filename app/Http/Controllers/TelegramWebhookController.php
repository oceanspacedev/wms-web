<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramBot;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pesan masuk dari bot Telegram (didaftarkan dengan `php artisan telegram:webhook`). Telegram mengirim
 * secret di header X-Telegram-Bot-Api-Secret-Token; request tanpa secret yang cocok ditolak.
 */
class TelegramWebhookController extends Controller
{
    #[ExcludeRouteFromDocs]
    public function __invoke(Request $request, TelegramBot $bot): Response
    {
        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $update = $request->json()->all();

        // Diproses setelah balasan 200 terkirim: jawaban AI bisa belasan detik, dan Telegram mengirim ulang
        // pesan yang tidak dibalas cepat. Error tetap 200 supaya pesan yang sama tidak dikirim terus-menerus.
        dispatch(function () use ($bot, $update): void {
            try {
                $bot->handle($update);
            } catch (Throwable $exception) {
                Log::error('Bot Telegram gagal memproses pesan: '.$exception->getMessage());
            }
        })->afterResponse();

        return response()->noContent(200);
    }
}

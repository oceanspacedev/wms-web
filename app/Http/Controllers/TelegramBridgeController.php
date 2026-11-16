<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramBot;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jembatan untuk bot Telegram gabungan (bot milik web lain yang juga melayani WMS). Bot itu meneruskan pesan
 * WMS ke sini dan mengirim jawabannya sendiri ke Telegram; WMS tidak menghubungi Telegram langsung.
 *
 * Request:  POST /api/telegram-bridge, header "Authorization: Bearer {TELEGRAM_BRIDGE_TOKEN}",
 *           body JSON {"chat_id": 123456789, "text": "/start wms_KODE" atau pertanyaan}
 * Response: {"reply": "teks HTML Telegram", "parse_mode": "HTML", "linked": true/false}
 *
 * Aturannya sama dengan bot WMS: chat harus dihubungkan dulu ke akun WMS ("/start wms_KODE" dari halaman Profil),
 * dan jawaban mengikuti hak akses akun itu.
 */
class TelegramBridgeController extends Controller
{
    #[ExcludeRouteFromDocs]
    public function __invoke(Request $request, TelegramBot $bot): JsonResponse
    {
        $token = (string) config('services.telegram.bridge_token');

        if ($token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            abort(401);
        }

        $validated = $request->validate([
            'chat_id' => ['required', 'integer'],
            'text' => ['required', 'string', 'max:4000'],
        ]);

        $reply = $bot->reply((int) $validated['chat_id'], trim($validated['text']));

        return response()->json([
            'reply' => $reply,
            'parse_mode' => 'HTML',
            'linked' => $bot->isLinked((int) $validated['chat_id']),
        ]);
    }
}

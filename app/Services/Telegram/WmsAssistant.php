<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

/**
 * Jawab pertanyaan bebas tentang data menu WMS lewat AI (API chat completions kompatibel OpenAI, mis. router).
 * AI hanya bisa membaca data lewat WmsDataTools, yang selalu dibatasi izin & hak lihat akun penanya.
 * Riwayat singkat per chat disimpan sebentar supaya pertanyaan lanjutan ("yang di Cirebon?") tetap nyambung.
 */
class WmsAssistant
{
    /**
     * Batas putaran AI ↔ alat untuk satu pertanyaan.
     */
    private const MAX_ROUNDS = 8;

    /**
     * Pesan (tanya-jawab) terakhir yang diingat per chat, dan berapa lama.
     */
    private const HISTORY_MESSAGES = 6;

    private const HISTORY_MINUTES = 30;

    public static function isConfigured(): bool
    {
        return filled(config('services.ai.base_url')) && filled(config('services.ai.api_key')) && filled(config('services.ai.model'));
    }

    public function answer(User $user, int $chatId, string $question): string
    {
        $history = Cache::get($this->historyKey($chatId), []);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($user)],
            ...$history,
            ['role' => 'user', 'content' => $question],
        ];
        $tools = new WmsDataTools($user);

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $message = $this->complete($messages);
            $toolCalls = $message['tool_calls'] ?? [];

            if ($toolCalls === []) {
                $answer = trim((string) ($message['content'] ?? ''));

                if ($answer === '') {
                    throw new RuntimeException('AI tidak memberi jawaban.');
                }

                Cache::put($this->historyKey($chatId), array_slice([
                    ...$history,
                    ['role' => 'user', 'content' => $question],
                    ['role' => 'assistant', 'content' => $answer],
                ], -self::HISTORY_MESSAGES), now()->addMinutes(self::HISTORY_MINUTES));

                return $answer;
            }

            $messages[] = ['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $toolCalls];

            foreach ($toolCalls as $call) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($this->runTool($tools, $call), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                ];
            }
        }

        throw new RuntimeException('AI terlalu banyak mencari data untuk satu pertanyaan.');
    }

    public function forget(int $chatId): void
    {
        Cache::forget($this->historyKey($chatId));
    }

    /**
     * @param  array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function runTool(WmsDataTools $tools, array $call): array
    {
        try {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['error' => 'Argumen alat bukan JSON yang valid.'];
        }

        return $tools->run((string) ($call['function']['name'] ?? ''), is_array($arguments) ? $arguments : []);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function complete(array $messages): array
    {
        $response = Http::withToken((string) config('services.ai.api_key'))
            ->timeout((int) config('services.ai.timeout', 45))
            ->asJson()
            ->post(rtrim((string) config('services.ai.base_url'), '/').'/chat/completions', [
                'model' => config('services.ai.model'),
                'messages' => $messages,
                'tools' => WmsDataTools::definitions(),
                'tool_choice' => 'auto',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI gagal menjawab (HTTP '.$response->status().').');
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            throw new RuntimeException('Format jawaban AI tidak dikenal.');
        }

        return $message;
    }

    private function systemPrompt(User $user): string
    {
        $today = now()->locale('id');
        $catalog = WmsDataCatalog::describe($user);

        return <<<PROMPT
            Kamu asisten WMS (sistem gudang & logistik) yang menjawab pertanyaan tentang data di menu WMS lewat Telegram.
            Hari ini {$today->translatedFormat('l, d F Y')} ({$today->toDateString()}). Yang bertanya: {$user->name}.

            Data yang boleh dibaca akun ini (pakai alat cari_data untuk daftar baris, rekap_data untuk jumlah/total per kelompok):
            {$catalog}

            Aturan:
            - Ambil data hanya lewat alat, jangan mengarang angka, nama, atau nomor dokumen. Pilih data yang paling cocok dengan pertanyaan;
              kalau ragu antara beberapa data (mis. surat_jalan dari aplikasi kurir vs data_pengiriman dari laporan CSA), cek keduanya.
            - "Hari ini", "kemarin", "minggu ini", "bulan ini" hitung dari tanggal hari ini di atas. Tanggal di filter berformat YYYY-MM-DD.
            - Untuk "berapa", "total", "paling banyak", perbandingan, atau tren pakai rekap_data, jangan menghitung sendiri dari daftar.
            - Kalau alat membalas error, perbaiki permintaannya. Kalau datanya kosong, katakan belum ada data untuk kriteria itu.
            - Data yang tidak ada di daftar di atas berarti akun ini tidak punya akses atau datanya belum tersedia di WMS: katakan begitu.
            - Jawab dalam bahasa Indonesia yang singkat dan jelas (maksimal ±15 baris), sebut nomor dokumen bila relevan.
            - Tulis rupiah seperti "Rp 1.500.000". Teks biasa saja: jangan pakai Markdown (*, #, tabel); boleh baris baru dan emoji seperlunya.
            - Kalau pertanyaan di luar urusan WMS, jelaskan singkat bahwa kamu hanya bisa membantu soal data WMS.
            PROMPT;
    }

    private function historyKey(int $chatId): string
    {
        return "telegram-ai-history:{$chatId}";
    }
}

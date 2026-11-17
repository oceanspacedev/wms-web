<?php

namespace App\Services\Telegram;

use App\Filament\Resources\TrackingOrders\TrackingOrderResource;
use App\Models\Depo;
use App\Models\TrackingOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bot Telegram WMS. Dengan AI (WmsAssistant), pertanyaan bebas tentang data semua menu WMS dijawab sesuai izin akun.
 * Tanpa AI (atau kalau AI gagal), bot menjawab perintah kata kunci Surat Jalan: "sj hari ini", "sj cirebon",
 * "cari 2610000123", "belum lengkap". Hanya chat yang sudah dihubungkan ke akun WMS (kode dari halaman Profil) yang dijawab.
 */
class TelegramBot
{
    /**
     * Kode hubungkan Telegram berlaku 15 menit.
     */
    public const LINK_CODE_MINUTES = 15;

    /**
     * Maksimal SJ yang dirinci dalam satu balasan; sisanya dibuka di WMS.
     */
    private const LIST_LIMIT = 25;

    /**
     * Kata yang diabaikan supaya pertanyaan biasa ("apakah ada nota surat jalan hari ini?") tetap dipahami.
     *
     * @var list<string>
     */
    private const FILLER_WORDS = ['apakah', 'apa', 'ada', 'nota', 'surat', 'jalan', 'sj', 'pada', 'di', 'yang', 'berapa', 'untuk', 'dong', 'ya', 'yg', 'tgl', 'tanggal', 'depo', 'kurir', 'dari', 'cek', 'lihat', 'hari'];

    public function __construct(private TelegramClient $telegram, private WmsAssistant $assistant) {}

    /**
     * Kode sekali pakai untuk menghubungkan chat Telegram ke akun; dikirim lewat "/start KODE".
     */
    public static function issueLinkCode(User $user): string
    {
        $code = Str::upper(Str::random(10));
        Cache::put("telegram-link:{$code}", $user->id, now()->addMinutes(self::LINK_CODE_MINUTES));

        return $code;
    }

    /**
     * Link yang langsung membuka bot dan mengirim kodenya (null kalau username bot belum diisi).
     * Kode diberi awalan WMS (mis. "wms_KODE") supaya bot gabungan bisa meneruskannya ke WMS.
     */
    public static function linkUrl(string $code): ?string
    {
        $bot = ltrim((string) config('services.telegram.bot_username'), '@');

        return $bot === '' ? null : "https://t.me/{$bot}?start=".self::startParameter($code);
    }

    /**
     * Teks setelah "/start": awalan WMS + kode.
     */
    public static function startParameter(string $code): string
    {
        return config('services.telegram.start_prefix', 'wms_').$code;
    }

    public function isLinked(int $chatId): bool
    {
        return User::query()->where('telegram_chat_id', $chatId)->exists();
    }

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        if (! is_array($message)) {
            return;
        }

        $chat = $message['chat'] ?? [];
        $chatId = $chat['id'] ?? null;
        $chatType = (string) ($chat['type'] ?? 'private');
        $text = trim((string) ($message['text'] ?? ''));

        // Mendukung chat pribadi serta grup / supergroup
        if ($chatId === null || ! in_array($chatType, ['private', 'group', 'supergroup'], true) || $text === '') {
            return;
        }

        $isGroup = in_array($chatType, ['group', 'supergroup'], true);
        $messageId = isset($message['message_id']) ? (int) $message['message_id'] : null;
        $senderId = isset($message['from']['id']) ? (int) $message['from']['id'] : (int) $chatId;
        $senderName = (string) ($message['from']['first_name'] ?? '');

        if ($isGroup) {
            $botUsername = ltrim((string) config('services.telegram.bot_username'), '@');

            // Cek apakah pesan di grup memang ditujukan untuk bot ini
            if (! $this->isMessageForBot($message, $text, $botUsername)) {
                return;
            }

            // Bersihkan mention @bot_username dari teks pertanyaan
            if ($botUsername !== '') {
                $text = trim((string) preg_replace('/@'.preg_quote($botUsername, '/').'\b/i', '', $text));
            }
        }

        // Jawaban AI bisa beberapa detik: tampilkan "sedang mengetik…" dulu
        if (WmsAssistant::isConfigured()) {
            $this->telegram->sendChatAction((int) $chatId, 'typing');
        }

        $reply = $this->reply((int) $chatId, $text, $senderId, $senderName, $isGroup);

        $this->telegram->sendMessage((int) $chatId, $reply, $isGroup ? $messageId : null);
    }

    /**
     * Balasan (HTML Telegram) untuk satu pesan.
     */
    public function reply(
        int $chatId,
        string $text,
        ?int $senderId = null,
        ?string $senderName = null,
        bool $isGroup = false,
    ): string {
        $senderId ??= $chatId;

        // "/sj@nama_bot hari ini" → "sj hari ini"
        $command = Str::lower(Str::squish((string) preg_replace('/^\/(\w+)(@\w+)?/u', '$1', $text)));

        // Di grup, cegah menghubungkan akun demi keamanan token/link code
        if ($isGroup && preg_match('/^start\b/i', $command) === 1) {
            $bot = ltrim((string) config('services.telegram.bot_username'), '@');
            $botLink = $bot !== '' ? "<b>@{$bot}</b>" : 'bot WMS';

            return "⚠️ <b>Hubungkan Akun di Chat Pribadi</b>\n\n"
                ."Demi keamanan akun Anda, jangan menghubungkan akun WMS di dalam grup.\n"
                ."Silakan buka chat pribadi dengan {$botLink} lalu jalankan perintah <code>/start</code> di sana.";
        }

        // "/start wms_KODE" (atau "/start KODE" tanpa awalan) di chat pribadi
        $prefix = preg_quote(Str::lower((string) config('services.telegram.start_prefix', 'wms_')), '/');

        if (preg_match('/^start\s+(?:'.$prefix.')?([a-z0-9]+)$/i', $command, $match) === 1) {
            return $this->link($senderId, Str::upper($match[1]));
        }

        $user = User::query()->where('telegram_chat_id', $senderId)->first();

        if ($user === null) {
            return $this->notLinkedMessage($senderName, $isGroup);
        }

        return match (true) {
            in_array($command, ['start', 'help', 'bantuan', 'menu'], true) => $this->help($user),
            in_array($command, ['putuskan', 'logout', 'stop'], true) => $this->unlink($user),
            // Pertanyaan bebas dijawab AI; tanpa AI (atau kalau AI gagal) pakai perintah kata kunci di bawah
            WmsAssistant::isConfigured() => $this->askAi($user, $senderId, $text, $command),
            default => $this->keywordReply($user, $command, $text),
        };
    }

    /**
     * Memastikan pesan di grup memang ditujukan untuk bot ini (mention, reply, atau command).
     *
     * @param  array<string, mixed>  $message
     */
    private function isMessageForBot(array $message, string $text, string $botUsername): bool
    {
        // 1. Reply ke pesan bot kita
        $replyFrom = $message['reply_to_message']['from'] ?? null;
        if (is_array($replyFrom) && ($replyFrom['is_bot'] ?? false) === true) {
            if ($botUsername === '' || strcasecmp((string) ($replyFrom['username'] ?? ''), $botUsername) === 0) {
                return true;
            }
        }

        // 2. Mention @bot_username di dalam teks
        if ($botUsername !== '' && preg_match('/@'.preg_quote($botUsername, '/').'\b/i', $text) === 1) {
            return true;
        }

        // 3. Command diawali slash (mis. "/sj", "/help", "/start", "/cari 123")
        if (str_starts_with($text, '/')) {
            // Jika ada bot target spesifik (mis. "/perintah@nama_bot_lain"), pastikan bukan untuk bot lain
            if (preg_match('/^\/\w+@([a-z0-9_]+)/i', $text, $matches) === 1) {
                return $botUsername === '' || strcasecmp($matches[1], $botUsername) === 0;
            }

            return true;
        }

        return false;
    }

    /**
     * Jawaban AI (teks biasa, di-escape untuk HTML Telegram); kalau AI gagal, jawaban perintah kata kunci.
     */
    private function askAi(User $user, int $chatId, string $text, string $command): string
    {
        try {
            return e($this->assistant->answer($user, $chatId, $text));
        } catch (Throwable $exception) {
            Log::warning('AI bot Telegram gagal: '.$exception->getMessage());

            return "⚠️ AI sedang tidak bisa dipakai, ini jawaban versi sederhana:\n\n".$this->keywordReply($user, $command, $text);
        }
    }

    private function keywordReply(User $user, string $command, string $text): string
    {
        return match (true) {
            // Nomor SJ diambil dari teks asli (huruf besar/kecil tidak diubah)
            (bool) preg_match('/^\/?cari\s+(.+)$/i', trim($text), $match) => $this->detail($user, trim($match[1])),
            str_contains($command, 'belum lengkap') || str_contains($command, 'foto') => $this->incomplete($user, $this->date($command) ?? today()),
            (bool) preg_match('/\b(sj|surat jalan|nota)\b/', $command) => $this->suratJalan($user, $command),
            default => 'Maaf, saya belum paham. Ketik <b>bantuan</b> untuk contoh pertanyaan.',
        };
    }

    /**
     * Ajakan login WMS untuk chat yang belum terhubung.
     */
    private function notLinkedMessage(?string $senderName = null, bool $isGroup = false): string
    {
        $profile = e(url('/admin/profile'));
        $bot = ltrim((string) config('services.telegram.bot_username'), '@');
        $botLink = $bot !== '' ? "<b>@{$bot}</b>" : 'bot WMS';

        if ($isGroup) {
            $greeting = ($senderName !== null && $senderName !== '') ? 'Halo <b>'.e($senderName).'</b>' : 'Halo';

            return "🔒 <b>{$greeting}, akun Telegram Anda belum terhubung ke WMS.</b>\n\n"
                ."Untuk dapat melihat data WMS di grup ini, silakan hubungkan akun Anda terlebih dahulu:\n"
                ."1. Login ke WMS lalu buka halaman Profil: <a href=\"{$profile}\">{$profile}</a>\n"
                ."2. Klik <b>Hubungkan Telegram</b>\n"
                ."3. Buka {$botLink} di chat pribadi lalu tekan <b>Start</b>.\n\n"
                .'Belum punya akun WMS? Hubungi admin WMS.';
        }

        return "🔒 <b>Fitur WMS perlu login dulu.</b>\n\n"
            ."Akun Telegram ini belum terhubung ke akun WMS. Caranya (cukup sekali):\n"
            ."1. Login WMS lalu buka halaman Profil: <a href=\"{$profile}\">{$profile}</a>\n"
            ."2. Klik <b>Hubungkan Telegram</b> → <b>Buka Telegram</b>\n"
            ."3. Tekan <b>Start</b> di chat ini\n\n"
            .'Belum punya akun WMS? Hubungi admin WMS.';
    }

    private function link(int $chatId, string $code): string
    {
        $userId = Cache::pull("telegram-link:{$code}");
        $user = $userId !== null ? User::query()->find($userId) : null;

        if ($user === null) {
            return 'Kode tidak valid atau sudah kedaluwarsa. Buat kode baru dari halaman Profil WMS.';
        }

        // Satu chat hanya untuk satu akun
        User::query()->where('telegram_chat_id', $chatId)->whereKeyNot($user->id)->update(['telegram_chat_id' => null]);
        $user->forceFill(['telegram_chat_id' => $chatId])->save();

        return 'Terhubung ke akun WMS <b>'.e($user->name)."</b> ✅\n\n".$this->help($user);
    }

    private function unlink(User $user): string
    {
        $this->assistant->forget((int) $user->telegram_chat_id);
        $user->forceFill(['telegram_chat_id' => null])->save();

        return 'Chat ini sudah diputus dari akun WMS. Hubungkan lagi dari halaman Profil WMS kapan saja.';
    }

    private function help(User $user): string
    {
        if (WmsAssistant::isConfigured()) {
            return 'Halo <b>'.e($user->name)."</b>, tanya apa saja soal data WMS dengan bahasa biasa, misalnya:\n\n"
                ."• <i>Ada nota surat jalan hari ini?</i>\n"
                ."• <i>Data pengiriman Cirebon bulan ini berapa SJ dan total ongkirnya?</i>\n"
                ."• <i>PO dari supplier X sudah datang belum?</i>\n"
                ."• <i>Barang apa yang paling laku di GMJKT di laporan penjualan terakhir?</i>\n"
                ."• <i>Gudang mana yang stoknya kritis di alokasi stok terbaru?</i>\n"
                ."• <i>Tarif kontrak termurah Cirebon ke Bandung?</i>\n"
                ."• <i>Invoice ekspedisi mana yang selisihnya paling besar?</i>\n\n"
                .'Bot hanya menampilkan data yang boleh dilihat akunmu di WMS. Ketik <code>putuskan</code> untuk memutus chat ini dari akun WMS.';
        }

        return 'Halo <b>'.e($user->name)."</b>, contoh pertanyaan:\n\n"
            ."• <code>sj hari ini</code> — ringkasan SJ hari ini\n"
            ."• <code>sj kemarin</code> atau <code>sj 05/11</code> — tanggal lain\n"
            ."• <code>sj cirebon</code> — rincian per depo (boleh ditambah tanggal)\n"
            ."• <code>sj agus</code> — rincian per kurir\n"
            ."• <code>cari 2610000123</code> — detail satu SJ\n"
            ."• <code>belum lengkap</code> — SJ hari ini yang fotonya belum lengkap\n"
            .'• <code>putuskan</code> — putus hubungan chat ini dari akun WMS';
    }

    /**
     * "sj", "sj kemarin", "sj cirebon 05/11", "apakah ada nota surat jalan hari ini", "sj agus".
     */
    private function suratJalan(User $user, string $command): string
    {
        $date = $this->date($command) ?? today();
        $words = array_values(array_diff(explode(' ', $this->withoutDate($command)), self::FILLER_WORDS));
        $filter = implode(' ', $words);

        if ($filter === '') {
            return $this->summary($user, $date);
        }

        $depo = Depo::findByText($filter);

        if ($depo !== null) {
            return $this->list(
                $this->orders($user, $date)->where('depo_id', $depo->id),
                e($depo->nama).' — '.$this->dateLabel($date),
            );
        }

        return $this->list(
            $this->orders($user, $date)->where('nama_pengirim', 'like', '%'.$filter.'%'),
            'Kurir "'.e($filter).'" — '.$this->dateLabel($date),
        );
    }

    private function summary(User $user, Carbon $date): string
    {
        $orders = $this->orders($user, $date)->with('depo')->get();

        if ($orders->isEmpty()) {
            return 'Belum ada surat jalan pada '.$this->dateLabel($date).'.';
        }

        $perDepo = $orders
            ->groupBy(fn (TrackingOrder $order): string => $order->depo?->nama ?? 'Tanpa depo')
            ->map->count()
            ->sortDesc()
            ->map(fn (int $count, string $depo): string => e($depo).' '.$count)
            ->implode(' · ');
        $incomplete = $orders->reject($this->hasPhotos(...))->count();

        return '📦 <b>Surat Jalan '.$this->dateLabel($date)."</b>\n"
            .$orders->count().' SJ · '.$this->rupiah((float) $orders->sum('jumlah_value_nota'))."\n\n"
            .$perDepo."\n"
            .($incomplete > 0 ? "\n⚠️ {$incomplete} SJ fotonya belum lengkap (ketik <code>belum lengkap</code>)\n" : '')
            ."\nKetik <code>sj nama-depo</code> untuk rincian, mis. <code>sj cirebon</code>.";
    }

    private function incomplete(User $user, Carbon $date): string
    {
        return $this->list(
            $this->orders($user, $date)->where(fn (Builder $query): Builder => $query
                ->whereNull('foto_nota_sj')->orWhere('foto_nota_sj', '')
                ->orWhereNull('foto_penerima')->orWhere('foto_penerima', '')),
            'Foto belum lengkap — '.$this->dateLabel($date),
        );
    }

    /**
     * @param  Builder<TrackingOrder>  $query
     */
    private function list(Builder $query, string $title): string
    {
        $total = (clone $query)->count();

        if ($total === 0) {
            return "<b>{$title}</b>\nTidak ada surat jalan.";
        }

        /** @var Collection<int, TrackingOrder> $orders */
        $orders = $query->latest()->limit(self::LIST_LIMIT)->get();
        $lines = $orders->values()->map(fn (TrackingOrder $order, int $index): string => ($index + 1).'. <code>'.e($order->no_sj).'</code> · '.e((string) $order->nama_dealer)
            .' · '.e((string) ($order->nama_pengirim ?: '-'))
            .' '.($this->hasPhotos($order) ? '✅' : '⚠️'));

        return "<b>{$title}</b> — {$total} SJ\n\n".$lines->implode("\n")
            .($total > self::LIST_LIMIT ? "\n… dan ".($total - self::LIST_LIMIT).' lainnya' : '')
            ."\n\n✅ foto lengkap · ⚠️ foto belum lengkap\n".'<a href="'.e(TrackingOrderResource::getUrl()).'">Buka di WMS</a>';
    }

    private function detail(User $user, string $noSj): string
    {
        $orders = TrackingOrder::query()->visibleTo($user)->with('depo')->where('no_sj', $noSj)->latest()->limit(5)->get();

        if ($orders->isEmpty()) {
            return 'SJ <code>'.e($noSj).'</code> tidak ditemukan.';
        }

        return $orders->map(fn (TrackingOrder $order): string => '📄 <b>SJ '.e($order->no_sj)."</b>\n"
            .'Dealer: '.e((string) $order->nama_dealer)."\n"
            .($order->alamat_dealer ? 'Alamat: '.e($order->alamat_dealer)."\n" : '')
            .'Tanggal kirim: '.($order->tanggal_pengiriman?->format('d/m/Y') ?? '-')."\n"
            .'Kurir: '.e((string) ($order->nama_pengirim ?: '-')).($order->depo ? ' ('.e($order->depo->nama).')' : '')."\n"
            .'Penerima: '.e((string) ($order->nama_penerima ?: '-'))."\n"
            .'Nilai nota: '.$this->rupiah((float) $order->jumlah_value_nota)."\n"
            .'Foto nota: '.(filled($order->foto_nota_sj) ? '✅' : '❌').' · Foto penerima: '.(filled($order->foto_penerima) ? '✅' : '❌')."\n"
            .'<a href="'.e(TrackingOrderResource::getUrl('view', ['record' => $order])).'">Buka di WMS</a>')
            ->implode("\n\n");
    }

    /**
     * @return Builder<TrackingOrder>
     */
    private function orders(User $user, Carbon $date): Builder
    {
        return TrackingOrder::query()->visibleTo($user)->onDay($date->toDateString());
    }

    private function hasPhotos(TrackingOrder $order): bool
    {
        return filled($order->foto_nota_sj) && filled($order->foto_penerima);
    }

    /**
     * Tanggal di pertanyaan: "hari ini", "kemarin", "05/11", "05/11/2026", "2026-11-05"; null kalau tidak ada.
     */
    private function date(string $command): ?Carbon
    {
        return match (true) {
            str_contains($command, 'kemarin') => today()->subDay(),
            str_contains($command, 'hari ini') => today(),
            (bool) preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $command, $m) => $this->safeDate((int) $m[1], (int) $m[2], (int) $m[3]),
            (bool) preg_match('/\b(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?\b/', $command, $m) => $this->safeDate(
                isset($m[3]) && $m[3] !== '' ? (int) (strlen($m[3]) === 2 ? '20'.$m[3] : $m[3]) : today()->year,
                (int) $m[2],
                (int) $m[1],
            ),
            default => null,
        };
    }

    private function withoutDate(string $command): string
    {
        $text = (string) preg_replace(['/\bhari ini\b/', '/\bkemarin\b/', '/\b\d{4}-\d{1,2}-\d{1,2}\b/', '/\b\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?\b/', '/[?!.,]/'], ' ', $command);

        return Str::squish($text);
    }

    private function safeDate(int $year, int $month, int $day): ?Carbon
    {
        return checkdate($month, $day, $year) ? Carbon::create($year, $month, $day)->startOfDay() : null;
    }

    private function dateLabel(Carbon $date): string
    {
        return $date->copy()->locale('id')->translatedFormat('l, d/m/Y');
    }

    private function rupiah(float $value): string
    {
        return 'Rp '.number_format($value, 0, ',', '.');
    }
}

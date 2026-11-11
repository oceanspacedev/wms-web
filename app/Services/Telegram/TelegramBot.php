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
     */
    public static function linkUrl(string $code): ?string
    {
        $bot = ltrim((string) config('services.telegram.bot_username'), '@');

        return $bot === '' ? null : "https://t.me/{$bot}?start={$code}";
    }

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        // Hanya chat pribadi; grup belum didukung
        if ($chatId === null || ($message['chat']['type'] ?? '') !== 'private' || $text === '') {
            return;
        }

        // Jawaban AI bisa beberapa detik: tampilkan "sedang mengetik…" dulu
        if (WmsAssistant::isConfigured()) {
            $this->telegram->sendChatAction((int) $chatId, 'typing');
        }

        $this->telegram->sendMessage((int) $chatId, $this->reply((int) $chatId, $text));
    }

    /**
     * Balasan (HTML Telegram) untuk satu pesan.
     */
    public function reply(int $chatId, string $text): string
    {
        // "/sj@nama_bot hari ini" → "sj hari ini"
        $command = Str::lower(Str::squish((string) preg_replace('/^\/(\w+)(@\w+)?/u', '$1', $text)));

        if (preg_match('/^start\s+([a-z0-9]+)$/i', $command, $match) === 1) {
            return $this->link($chatId, Str::upper($match[1]));
        }

        $user = User::query()->where('telegram_chat_id', $chatId)->first();

        if ($user === null) {
            return "Chat ini belum terhubung ke akun WMS.\n\nBuka WMS → klik foto profil → <b>Profil</b> → <b>Hubungkan Telegram</b>, lalu ikuti petunjuknya.";
        }

        return match (true) {
            in_array($command, ['start', 'help', 'bantuan', 'menu'], true) => $this->help($user),
            in_array($command, ['putuskan', 'logout', 'stop'], true) => $this->unlink($user),
            // Pertanyaan bebas dijawab AI; tanpa AI (atau kalau AI gagal) pakai perintah kata kunci di bawah
            WmsAssistant::isConfigured() => $this->askAi($user, $chatId, $text, $command),
            default => $this->keywordReply($user, $command, $text),
        };
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

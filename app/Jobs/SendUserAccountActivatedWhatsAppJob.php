<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WhatsAppNotificationService;
use App\Support\WhatsAppNumber;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kirim WhatsApp "akun sudah aktif" + link aplikasi mobile ke user yang baru dibuat.
 * Kegagalan WhatsApp hanya dicatat ke log; user tetap tersimpan.
 * Payload dienkripsi karena bisa membawa password awal, supaya tidak terbaca di antrean atau dashboard Horizon.
 * Pesan dikirim bergiliran, satu dalam satu waktu, supaya nomor gateway tidak terlihat seperti blast lalu kena banned.
 */
class SendUserAccountActivatedWhatsAppJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * Jeda acak (detik) sebelum pesan berikutnya dalam satu import dikirim.
     *
     * @var array{int, int}
     */
    public const GAP_SECONDS = [10, 20];

    public const OVERLAP_KEY = 'whatsapp-account-activated';

    /** Error selain gagal kirim WhatsApp (yang sudah ditangani di handle) tidak diulang. */
    public int $maxExceptions = 1;

    public int $timeout = 30;

    /** Habis waktu dianggap gagal dan tidak diulang, supaya pesan tidak terkirim dua kali. */
    public bool $failOnTimeout = true;

    /**
     * @param  string|null  $password  password awal yang ikut dikirim (akun hasil import); null untuk login OTP saja
     */
    public function __construct(public int $userId, public ?string $password = null) {}

    /**
     * Kirim pesan untuk banyak akun satu per satu: pesan berikutnya baru diproses setelah pesan sebelumnya selesai,
     * lalu menunggu jeda acak. Antrean dimulai setelah transaksi selesai, supaya akunnya sudah tersimpan.
     *
     * @param  list<self>  $jobs
     */
    public static function dispatchOneByOne(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }

        foreach (array_slice($jobs, 1) as $job) {
            $job->delay(random_int(...self::GAP_SECONDS));
        }

        DB::afterCommit(fn () => Bus::chain($jobs)->dispatch());
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // Kunci bersama semua pesan akun aktif: pesan dari import lain atau user baru menunggu giliran
        return [(new WithoutOverlapping(self::OVERLAP_KEY))->shared()->releaseAfter(10)->expireAfter(60)];
    }

    /**
     * Selama menunggu giliran, job dilepas kembali ke antrean; itu tidak dihitung gagal sampai batas waktu ini.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(WhatsAppNotificationService $whatsApp): void
    {
        $user = User::query()->find($this->userId);

        if (! $user || blank($user->whatsapp_number)) {
            return;
        }

        $number = WhatsAppNumber::normalize((string) $user->whatsapp_number);

        if (! WhatsAppNumber::isValid($number)) {
            return;
        }

        try {
            $whatsApp->sendAccountActivated($number, $user, $this->password);
        } catch (Throwable $exception) {
            Log::warning('Gagal mengirim WhatsApp akun aktif ke user baru', [
                'user_id' => $user->id,
                'whatsapp_last4' => substr($number, -4),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

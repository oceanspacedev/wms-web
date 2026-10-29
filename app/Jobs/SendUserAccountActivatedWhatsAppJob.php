<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WhatsAppNotificationService;
use App\Support\WhatsAppNumber;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kirim WhatsApp "akun sudah aktif" + link aplikasi mobile ke user yang baru dibuat.
 * Kegagalan WhatsApp hanya dicatat ke log; user tetap tersimpan.
 * Payload dienkripsi karena bisa membawa password awal, supaya tidak terbaca di antrean atau dashboard Horizon.
 */
class SendUserAccountActivatedWhatsAppJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  string|null  $password  password awal yang ikut dikirim (akun hasil import kurir Talenta); null untuk login OTP saja
     */
    public function __construct(public int $userId, public ?string $password = null) {}

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

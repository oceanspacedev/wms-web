<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\WhatsAppNotificationService;
use App\Support\WhatsAppNumber;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kirim WhatsApp "akun sudah aktif" + link aplikasi mobile ke user yang baru dibuat.
 * Kegagalan WhatsApp hanya dicatat ke log; user tetap tersimpan.
 */
class SendUserAccountActivatedWhatsAppJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $userId) {}

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
            $whatsApp->sendAccountActivated($number, $user);
        } catch (Throwable $exception) {
            Log::warning('Gagal mengirim WhatsApp akun aktif ke user baru', [
                'user_id' => $user->id,
                'whatsapp_last4' => substr($number, -4),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

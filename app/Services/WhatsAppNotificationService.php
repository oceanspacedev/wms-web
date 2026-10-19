<?php

namespace App\Services;

use App\Models\User;
use RuntimeException;

class WhatsAppNotificationService
{
    public function __construct(
        private WhatsAppGateway $gateway,
        private WhatsAppMessageBuilder $messages,
    ) {}

    public function sendOtp(string $target, string $otp): void
    {
        if (app()->environment('testing') && blank(config('services.whatsapp_gateway.url'))) {
            return;
        }

        if (! $this->gateway->send($target, $this->messages->otpMessage($otp))) {
            throw new RuntimeException('Gagal mengirim OTP WhatsApp.');
        }
    }

    public function sendAccountActivated(string $target, User $user): void
    {
        if (app()->environment('testing') && blank(config('services.whatsapp_gateway.url'))) {
            return;
        }

        if (! $this->gateway->send($target, $this->messages->accountActivatedMessage($user))) {
            throw new RuntimeException('Gagal mengirim notifikasi WhatsApp akun aktif.');
        }
    }
}

<?php

namespace App\Services;

use App\Models\User;

class WhatsAppMessageBuilder
{
    public function otpMessage(string $otp): string
    {
        $minutes = max(1, (int) ceil(((int) config('services.whatsapp.otp_expires_in', 300)) / 60));
        $appName = (string) config('app.name');

        return "Kode masuk {$appName}: {$otp}. Berlaku {$minutes} menit. Jangan bagikan kode ini kepada siapa pun.";
    }

    /**
     * Pesan sambutan untuk user baru: akun aktif, info login (tanpa password), dan link aplikasi mobile.
     */
    public function accountActivatedMessage(User $user): string
    {
        $appName = (string) config('app.name');
        $name = filled($user->name) ? (string) $user->name : 'User';

        $loginLines = array_filter([
            filled($user->username) ? "Username: {$user->username}" : null,
            filled($user->email) ? "Email: {$user->email}" : null,
        ]);

        return implode("\n", [
            "Halo {$name},",
            '',
            "Akun {$appName} Anda sudah aktif dan bisa langsung digunakan.",
            '',
            'Info login:',
            ...$loginLines,
            'Password: gunakan password yang diberikan admin.',
            '',
            "Download aplikasi {$appName}:",
            'Android (Play Store): '.$this->linkOrFallback('services.wms_app.playstore_url', 'silakan cari di Google Play Store'),
            'iOS (TestFlight): '.$this->linkOrFallback('services.wms_app.testflight_url', 'silakan minta undangan TestFlight ke admin'),
        ]);
    }

    private function linkOrFallback(string $configKey, string $fallback): string
    {
        $url = trim((string) config($configKey, ''));

        return $url !== '' ? $url : $fallback;
    }
}

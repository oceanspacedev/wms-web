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
     * Pesan sambutan untuk user baru: nomor WhatsApp sudah bisa dipakai login aplikasi mobile, link download,
     * dan langkah login. Format tebal memakai *teks* WhatsApp; link berdiri sendiri di satu baris
     * (tanpa titik di belakang) supaya tidak ikut terbaca sebagai bagian URL.
     */
    public function accountActivatedMessage(User $user): string
    {
        $appName = (string) config('services.wms_app.name', 'WMS');
        $name = filled($user->name) ? (string) $user->name : 'User';

        return implode("\n", [
            "Halo *{$name}*,",
            '',
            "Nomor WhatsApp Anda sudah terdaftar di *{$appName}* dan bisa digunakan untuk login aplikasi.",
            '',
            'Silakan download aplikasinya terlebih dahulu:',
            '',
            '*Android (Play Store)*',
            $this->linkOrFallback('services.wms_app.playstore_url', 'Minta link Play Store ke admin'),
            '',
            '*iPhone (TestFlight)*',
            $this->linkOrFallback('services.wms_app.testflight_url', 'Minta undangan TestFlight ke admin'),
            '_Install aplikasi TestFlight dari App Store terlebih dahulu._',
            '',
            '*Cara login:*',
            '1. Buka aplikasi setelah terpasang.',
            '2. Masukkan nomor WhatsApp ini.',
            '3. Masukkan kode OTP yang dikirim ke WhatsApp Anda.',
            '',
            'Terima kasih.',
        ]);
    }

    private function linkOrFallback(string $configKey, string $fallback): string
    {
        $url = trim((string) config($configKey, ''));

        return $url !== '' ? $url : $fallback;
    }
}

<?php

namespace App\Services;

use App\Jobs\SendUserAccountActivatedWhatsAppJob;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Buat akun aplikasi WMS dari hasil import (Talenta, spreadsheet): username unik otomatis, role panel_user,
 * dan password awal acak yang hanya dikirim ke WhatsApp pemilik akun; pemilik akun juga bisa login dengan OTP WhatsApp.
 */
class ImportedAccountCreator
{
    /**
     * @param  string  $usernameSeed  teks asal username, mis. bagian email sebelum "@" atau nama lengkap
     * @param  string  $usernameFallback  awalan username kalau teks asalnya terlalu pendek
     * @return SendUserAccountActivatedWhatsAppJob pesan WhatsApp berisi password awal; belum dikirim, kirim lewat dispatchOneByOne()
     */
    public function create(
        string $name,
        string $whatsAppNumber,
        ?string $email,
        string $usernameSeed,
        string $usernameFallback,
    ): SendUserAccountActivatedWhatsAppJob {
        $password = $this->readablePassword();
        $user = User::create([
            'name' => $name,
            'username' => $this->uniqueUsername($usernameSeed, $usernameFallback),
            'email' => $email,
            'whatsapp_number' => $whatsAppNumber,
            'password' => $password,
        ]);
        $user->syncRoles([Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web'])]);

        return new SendUserAccountActivatedWhatsAppJob((int) $user->id, $password);
    }

    /**
     * Password acak 10 karakter tanpa huruf/angka yang mirip (0/o, 1/l/i), supaya mudah diketik ulang dari pesan WhatsApp.
     */
    private function readablePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';

        return implode('', array_map(fn (): string => $alphabet[random_int(0, strlen($alphabet) - 1)], range(1, 10)));
    }

    /**
     * Username huruf kecil (mis. "dino_liardi_putra"), diberi akhiran angka kalau sudah dipakai.
     * Karakter yang tidak boleh ada di username, seperti titik dan spasi, diganti garis bawah.
     */
    private function uniqueUsername(string $seed, string $fallback): string
    {
        $slug = preg_replace('/[^a-z0-9_-]+/', '_', Str::lower(Str::ascii($seed))) ?? '';
        $base = trim(substr(trim($slug, '_-'), 0, 45), '_-');

        if (strlen($base) < 3) {
            $base = $fallback.($base === '' ? '' : '_'.$base);
        }

        $username = $base;

        for ($suffix = 2; User::query()->where('username', $username)->exists(); $suffix++) {
            $username = "{$base}_{$suffix}";
        }

        return $username;
    }
}

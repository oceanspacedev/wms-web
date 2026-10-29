<?php

namespace App\Services;

use App\Jobs\SendUserAccountActivatedWhatsAppJob;
use App\Models\User;
use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Buat akun aplikasi WMS untuk kurir gudang dari export direktori karyawan Talenta (JSON).
 * Password awal dibuat acak per akun dan hanya dikirim ke WhatsApp kurir; kurir juga bisa login dengan OTP WhatsApp.
 */
class TalentaCourierImporter
{
    /** Organisasi Talenta tempat kurir gudang berada. */
    public const ORGANIZATION = 'SUPPLY CHAIN';

    /**
     * Jabatan Talenta yang mengandung salah satu kata ini dianggap kurir ("KURIR", "STAFF KURIR", "DRIVER LOGISTIK").
     *
     * @var list<string>
     */
    public const JOB_KEYWORDS = ['KURIR', 'DRIVER'];

    public function __construct(private ImportedAccountCreator $accounts) {}

    /**
     * Semua akun dibuat dalam satu transaksi: kalau gagal di tengah jalan, tidak ada akun yang tersimpan.
     * Karyawan yang email atau nomor WhatsApp-nya sudah dipakai akun lain dilewati, jadi file yang sama aman diimpor ulang.
     *
     * @return array{created: list<string>, existing: list<string>, incomplete: list<string>}
     */
    public function import(string $json, bool $sendWhatsApp = true): array
    {
        $couriers = array_values(array_filter($this->employees($json), $this->isWarehouseCourier(...)));

        return DB::transaction(function () use ($couriers, $sendWhatsApp): array {
            $result = ['created' => [], 'existing' => [], 'incomplete' => []];
            $messages = [];

            foreach ($couriers as $employee) {
                $name = $this->name($employee);
                $email = Str::lower(trim((string) ($employee['email'] ?? '')));
                $whatsAppNumber = WhatsAppNumber::normalize((string) ($employee['mobile_phone'] ?? ''));

                if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! WhatsAppNumber::isValid($whatsAppNumber)) {
                    $result['incomplete'][] = $name;

                    continue;
                }

                $taken = User::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->orWhere('whatsapp_number', $whatsAppNumber)
                    ->exists();

                if ($taken) {
                    $result['existing'][] = $name;

                    continue;
                }

                // Username dari bagian email sebelum "@" (mis. "dinoliardii1")
                $messages[] = $this->accounts->create($name, $whatsAppNumber, $email, Str::before($email, '@'), 'kurir');

                $result['created'][] = $name;
            }

            if ($sendWhatsApp) {
                SendUserAccountActivatedWhatsAppJob::dispatchOneByOne($messages);
            }

            return $result;
        });
    }

    /**
     * Baris karyawan dari response API Talenta (`data.data`), atau langsung berupa daftar karyawan.
     *
     * @return list<array<string, mixed>>
     */
    private function employees(string $json): array
    {
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('File bukan JSON yang valid.');
        }

        $rows = $payload['data']['data'] ?? $payload['data'] ?? $payload;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('Format JSON tidak dikenali. Gunakan export daftar karyawan Talenta.');
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function isWarehouseCourier(array $employee): bool
    {
        $organization = Str::upper(trim((string) ($employee['organization'] ?? '')));
        $job = Str::upper((string) ($employee['job'] ?? ''));

        return $organization === self::ORGANIZATION
            && preg_match('/\b('.implode('|', self::JOB_KEYWORDS).')\b/', $job) === 1;
    }

    /**
     * Nama di Talenta ditulis kapital semua; disimpan dengan huruf awal kapital supaya enak dibaca di sapaan WhatsApp.
     *
     * @param  array<string, mixed>  $employee
     */
    private function name(array $employee): string
    {
        $fullName = Str::squish(($employee['first_name'] ?? '').' '.($employee['last_name'] ?? ''));

        return mb_convert_case($fullName, MB_CASE_TITLE, 'UTF-8');
    }
}

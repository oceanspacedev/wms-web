<?php

namespace App\Support;

class Rupiah
{
    /**
     * Nominal dari sel Excel/CSV: angka mentah ("1500.0", "19665.3") atau teks rupiah ("Rp 2.900", "2.900,50", "1,500").
     * Angka mentah tidak boleh dibuang titiknya: "1500.0" bukan 15000.
     */
    public static function parse(?string $value): float
    {
        $value = trim((string) $value);

        if (is_numeric($value)) {
            return (float) $value;
        }

        $number = preg_replace('/[^\d.,]/', '', $value) ?? '';

        // Titik/koma terakhir yang diikuti 1–2 digit adalah sen; pemisah lain adalah ribuan
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $number, $match)) {
            return (float) (preg_replace('/\D/', '', $match[1]).'.'.$match[2]);
        }

        return (float) (preg_replace('/\D/', '', $number) ?: 0);
    }
}

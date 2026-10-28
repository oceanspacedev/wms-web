<?php

namespace App\Support;

/**
 * Nama barang dari laporan ERP, mis. "TECNO - HP 4G - CAMON 50 - CN5 8/128 - N - NEBULA TITANIUM".
 */
class ItemName
{
    /**
     * Pisahkan warna (bagian terakhir) dari nama unit. Nama aksesoris tidak selalu diakhiri warna
     * ("WATCH - WATCH NEO WN01 - BLACK - TECNO"), jadi tidak dipisah.
     *
     * @return array{0: string, 1: ?string, 2: bool} [model, warna, HP/tablet?]
     */
    public static function describe(string $name, string $sortCode = ''): array
    {
        $parts = array_map('trim', explode(' - ', $name));
        $hasUnitType = count($parts) >= 3 && preg_match('/^(HP|TAB|TABLET)\b/i', $parts[1]) === 1;
        $isUnit = $hasUnitType || in_array(strtoupper(trim($sortCode)), ['ANDROID', 'EXCLUSIVE'], true);

        if (! $hasUnitType) {
            return [$name, null, $isUnit];
        }

        $warna = array_pop($parts);

        return [implode(' - ', $parts), $warna !== '' ? $warna : null, $isUnit];
    }

    /**
     * Model tanpa brand, jenis, dan kode "N": "TECNO - HP 4G - CAMON 50 - CN5 8/128 - N" → "CAMON 50 CN5 8/128".
     */
    public static function shortModel(string $model, ?string $brand = null): string
    {
        $parts = array_map('trim', explode(' - ', $model));

        if (count($parts) >= 3 && preg_match('/^(HP|TAB|TABLET)\b/i', $parts[1]) === 1) {
            $parts = array_slice($parts, 2);
        }

        $name = implode(' ', array_filter($parts, fn (string $part): bool => ! in_array(strtoupper($part), ['', 'N'], true)));

        // "HP REALME C100i 6GB 128GB ..." → "C100i 6GB 128GB ..."; nama produk seperti "MOTOPAD 60 LITE" tetap utuh
        if (preg_match('/^HP\s+/i', $name) === 1) {
            $name = (string) preg_replace('/^HP\s+/i', '', $name);

            if (filled($brand) && stripos($name, $brand.' ') === 0) {
                $name = substr($name, strlen($brand) + 1);
            }
        }

        return $name !== '' ? $name : $model;
    }

    /**
     * Nama ringkas untuk tabel: "TECNO CAMON 50 CN5 8/128 · NEBULA TITANIUM".
     */
    public static function label(string $name, ?string $brand = null, string $sortCode = ''): string
    {
        [$model, $warna] = self::describe($name, $sortCode);
        $short = self::shortModel($model, $brand);

        if (filled($brand) && stripos($short, $brand) !== 0) {
            $short = strtoupper($brand).' '.$short;
        }

        return $warna !== null ? $short.' · '.$warna : $short;
    }
}

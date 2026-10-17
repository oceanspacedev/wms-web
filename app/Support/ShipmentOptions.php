<?php

namespace App\Support;

use App\Models\CsaShipment;
use App\Models\Expedition;
use App\Models\WarehouseMapping;

/**
 * Pilihan dropdown Data Pengiriman, mengikuti dropdown per tab di spreadsheet "Update Pengiriman"
 * (config/data_pengiriman.php). Nilai lama di luar daftar tetap ditampilkan agar tidak hilang saat diedit.
 */
final class ShipmentOptions
{
    /**
     * Tab cabang: urutan sama dengan spreadsheet, lalu cabang lain yang sudah punya data atau pemetaan gudang.
     *
     * @return list<string>
     */
    public static function tabs(): array
    {
        return once(function (): array {
            $configured = array_map('trim', (array) config('data_pengiriman.tabs', []));

            $others = CsaShipment::query()->distinct()->pluck('target_sheet')
                ->merge(WarehouseMapping::query()->where('is_active', true)->distinct()->pluck('target_sheet'))
                ->map(fn (?string $name): string => trim((string) $name))
                ->filter()
                ->reject(fn (string $name): bool => in_array(mb_strtoupper($name), array_map('mb_strtoupper', $configured), true))
                ->unique()
                ->sort()
                ->values()
                ->all();

            return [...$configured, ...$others];
        });
    }

    /**
     * @return array<string, string>
     */
    public static function cabang(): array
    {
        return self::same(self::tabs());
    }

    /**
     * @return array<string, string>
     */
    public static function ekspedisi(?string $cabang = null, ?string $current = null): array
    {
        return self::forField('nama_ekspedisi', $cabang, $current, withMasterEkspedisi: true);
    }

    /**
     * @return array<string, string>
     */
    public static function ketentuanBiayaKirim(?string $cabang = null, ?string $current = null): array
    {
        return self::forField('ketentuan_biaya_kirim', $cabang, $current);
    }

    /**
     * @return array<string, string>
     */
    public static function statusPembayaran(?string $cabang = null, ?string $current = null): array
    {
        return self::forField('status_pembayaran', $cabang, $current);
    }

    /**
     * @return array<string, string>
     */
    public static function badanUsaha(?string $cabang = null, ?string $current = null): array
    {
        return self::forField('badan_usaha', $cabang, $current);
    }

    /**
     * @return array<string, string>
     */
    public static function brand(?string $cabang = null, ?string $current = null): array
    {
        return self::forField('brand', $cabang, $current);
    }

    /**
     * @return array<string, string>
     */
    public static function namaKota(?string $cabang = null, ?string $current = null): array
    {
        return self::forField('nama_kota', $cabang, $current);
    }

    /**
     * Dropdown tab di spreadsheet kalau ada. Kalau tab itu tidak punya dropdown untuk kolom ini (di spreadsheet
     * diketik bebas), pakai gabungan dropdown semua tab + nilai yang sudah dipakai cabang itu.
     *
     * @return array<string, string>
     */
    private static function forField(string $field, ?string $cabang, ?string $current, bool $withMasterEkspedisi = false): array
    {
        $options = once(function () use ($field, $cabang, $withMasterEkspedisi): array {
            $perTab = (array) config('data_pengiriman.options', []);
            $tab = self::configKey($cabang, array_keys($perTab));

            if ($tab !== null && ! empty($perTab[$tab][$field])) {
                return self::clean($perTab[$tab][$field]);
            }

            $values = collect($perTab)->pluck($field)->filter()->flatten()->all();

            if ($withMasterEkspedisi) {
                $values = [...$values, ...Expedition::query()->where('is_active', true)->orderBy('name')->pluck('name')->all()];
            }

            if (filled($cabang)) {
                $values = [...$values, ...CsaShipment::query()
                    ->where('target_sheet', $cabang)
                    ->whereNotNull($field)
                    ->where($field, '!=', '')
                    ->distinct()
                    ->limit(200)
                    ->pluck($field)
                    ->all()];
            }

            return self::clean($values);
        });

        if (filled($current) && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * @param  list<string>  $tabs
     */
    private static function configKey(?string $cabang, array $tabs): ?string
    {
        if (blank($cabang)) {
            return null;
        }

        foreach ($tabs as $tab) {
            if (mb_strtoupper(trim($tab)) === mb_strtoupper(trim($cabang))) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    private static function clean(array $values): array
    {
        $clean = [];

        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ! array_key_exists(mb_strtoupper($value), array_change_key_case($clean, CASE_UPPER))) {
                $clean[$value] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function same(array $values): array
    {
        return array_combine($values, $values);
    }
}

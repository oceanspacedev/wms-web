<?php

namespace App\Support;

use App\Filament\Resources\CsaShipments\Pages\ListCsaShipments;
use App\Models\CsaShipment;
use App\Models\Expedition;
use App\Models\WarehouseMapping;

/**
 * Pilihan dropdown Data Pengiriman, mengikuti dropdown per tab di spreadsheet "Update Pengiriman"
 * (config/data_pengiriman.php). Nilai lama di luar daftar tetap ditampilkan agar tidak hilang saat diedit.
 */
final class ShipmentOptions
{
    /** Tab dengan list panjang nama driver/daerah spesifik yang tidak perlu membebani dropdown umum cabang lain. */
    private const EXCLUDED_FALLBACK_TABS = ['PALU', 'MAKASSAR'];

    /**
     * Tab cabang: urutan sama dengan spreadsheet, lalu cabang lain yang sudah punya data atau pemetaan gudang.
     *
     * @return list<string>
     */
    public static function tabs(): array
    {
        return CsaShipment::cached('shipment_options:tabs', function (): array {
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
        $rawOptions = self::forField('badan_usaha', $cabang, null);
        $options = [];

        foreach ($rawOptions as $value) {
            $canonical = ListCsaShipments::canonicalBadanUsaha((string) $value);
            if ($canonical !== '') {
                $options[$canonical] = $canonical;
            }
        }

        if (filled($current)) {
            $canonicalCurrent = ListCsaShipments::canonicalBadanUsaha($current) ?: trim($current);
            $options[$canonicalCurrent] = $canonicalCurrent;
        }

        return $options;
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
        $perTab = (array) config('data_pengiriman.options', []);
        $tab = self::configKey($cabang, array_keys($perTab));

        if ($tab !== null && ! empty($perTab[$tab][$field])) {
            $options = self::clean($perTab[$tab][$field]);
        } else {
            $options = CsaShipment::cached(
                'shipment_options:base:'.$field.':'.($withMasterEkspedisi ? '1' : '0'),
                function () use ($perTab, $field, $withMasterEkspedisi): array {
                    $values = collect($perTab)
                        ->except(self::EXCLUDED_FALLBACK_TABS)
                        ->pluck($field)
                        ->filter()
                        ->flatten()
                        ->all();

                    if ($withMasterEkspedisi) {
                        $masterExpeditions = Expedition::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name')
                            ->all();
                        $values = [...$values, ...$masterExpeditions];
                    }

                    return self::clean($values);
                },
            );

            if (filled($cabang)) {
                $byCabang = self::usedValuesByCabang($field);
                $branchValues = $byCabang[mb_strtoupper(trim($cabang))] ?? [];
                if ($branchValues !== []) {
                    $options = self::clean([...$options, ...$branchValues]);
                }
            }
        }

        if (filled($current) && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function usedValuesByCabang(string $field): array
    {
        return CsaShipment::cached('shipment_options:used_by_cabang:'.$field, function () use ($field): array {
            $grouped = [];

            CsaShipment::query()
                ->toBase()
                ->select(['target_sheet', $field])
                ->whereNotNull('target_sheet')
                ->whereNotNull($field)
                ->where($field, '!=', '')
                ->distinct()
                ->get()
                ->each(function (object $row) use ($field, &$grouped): void {
                    $cabangKey = mb_strtoupper(trim((string) $row->target_sheet));
                    if ($cabangKey === '') {
                        return;
                    }

                    if (count($grouped[$cabangKey] ?? []) < 200) {
                        $grouped[$cabangKey][] = (string) $row->{$field};
                    }
                });

            return $grouped;
        });
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
        $seen = [];

        foreach ($values as $value) {
            $value = trim(str_replace('"&"', '', (string) $value));
            if ($value === '') {
                continue;
            }

            $upper = mb_strtoupper($value);
            if (! isset($seen[$upper])) {
                $seen[$upper] = true;
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

<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class MediaFileName
{
    /**
     * Nama file foto yang mudah diaudit: {identitas}_{jenis}_{Ymd-His}_{hash5}.{ext},
     * contoh `sj-2026-001_nota_20261019-143005_k3x9q.jpg`. Identitas diambil dari data
     * (no_sj, no_do, username), hash 5 karakter hanya untuk mencegah nama bentrok.
     */
    public static function make(UploadedFile $file, ?string $identifier, string $kind): string
    {
        $identifier = Str::slug(str_replace(['/', '\\', '_', '.'], '-', (string) $identifier));
        $identifier = rtrim(Str::substr($identifier, 0, 40), '-') ?: 'tanpa-id';

        return sprintf(
            '%s_%s_%s_%s.%s',
            $identifier,
            $kind,
            now()->format('Ymd-His'),
            Str::lower(Str::random(5)),
            $file->extension() ?: (Str::lower($file->getClientOriginalExtension()) ?: 'jpg'),
        );
    }

    /**
     * Simpan ke disk default (FILESYSTEM_DISK) dengan nama dari make(), kembalikan path relatifnya.
     */
    public static function store(UploadedFile $file, string $directory, ?string $identifier, string $kind): string
    {
        return $file->storeAs($directory, self::make($file, $identifier, $kind));
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MediaUrl
{
    /**
     * URL file di disk default (FILESYSTEM_DISK), mengikuti aturan Filament:
     * disk `public` pakai URL biasa, disk lain (`local`, `s3`) pakai temporary URL.
     */
    public static function for(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $diskName = config('filesystems.default');
        $disk = Storage::disk($diskName);

        if ($diskName !== 'public') {
            try {
                return $disk->temporaryUrl(
                    $path,
                    now()->addMinutes(config('filament.temporary_file_url_expiry_minutes', 30))->endOfHour(),
                );
            } catch (Throwable) {
                // Driver tidak mendukung temporary URL.
            }
        }

        return $disk->url($path);
    }
}

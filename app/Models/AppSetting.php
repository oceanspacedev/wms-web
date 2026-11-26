<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class AppSetting extends Model
{
    public const KEY_DATA_PENGIRIMAN_RESET_PASSWORD_HASH = 'data_pengiriman_reset_password_hash';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    public static function hasDataPengirimanResetPassword(): bool
    {
        return filled(static::getValue(self::KEY_DATA_PENGIRIMAN_RESET_PASSWORD_HASH));
    }

    public static function verifyDataPengirimanResetPassword(string $plainPassword): bool
    {
        $hash = static::getValue(self::KEY_DATA_PENGIRIMAN_RESET_PASSWORD_HASH);

        return filled($hash) && Hash::check($plainPassword, (string) $hash);
    }

    public static function setDataPengirimanResetPassword(string $plainPassword): void
    {
        static::setValue(self::KEY_DATA_PENGIRIMAN_RESET_PASSWORD_HASH, Hash::make($plainPassword));
    }
}

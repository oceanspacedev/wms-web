<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\MediaUrl;
use App\Support\WhatsAppNumber;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'email', 'email_verified_at', 'password', 'whatsapp_number', 'whatsapp_verified_at', 'avatar_url', 'kode_gudang_arsip', 'cabang'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPanelShield, HasRoles, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (is_string($user->username)) {
                $username = Str::lower(trim($user->username));
                $user->username = $username === '' ? null : $username;
            }

            if (filled($user->email)) {
                if (($user->isDirty('email') || blank($user->email_verified_at)) && ! $user->isDirty('email_verified_at')) {
                    $user->email_verified_at = now();
                }
            } else {
                $user->email_verified_at = null;
            }

            if (blank($user->whatsapp_number)) {
                $user->whatsapp_number = null;
                $user->whatsapp_verified_at = null;
            } else {
                $user->whatsapp_number = WhatsAppNumber::normalize((string) $user->whatsapp_number);

                if (($user->isDirty('whatsapp_number') || blank($user->whatsapp_verified_at)) && ! $user->isDirty('whatsapp_verified_at')) {
                    $user->whatsapp_verified_at = now();
                }
            }

            // Sync email_verified_at with whatsapp_verified_at so both stay in lockstep.
            if ($user->isDirty('whatsapp_verified_at') && ! $user->isDirty('email_verified_at')) {
                $user->email_verified_at = $user->whatsapp_verified_at;
            } elseif ($user->isDirty('email_verified_at') && ! $user->isDirty('whatsapp_verified_at') && filled($user->whatsapp_number)) {
                $user->whatsapp_verified_at = $user->email_verified_at;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'whatsapp_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canImpersonate(): bool
    {
        return $this->hasRole('super_admin')
            || $this->can('ImpersonateUser')
            || $this->can('impersonate_user')
            || $this->can('impersonate');
    }

    public function canBeImpersonated(): bool
    {
        return ! $this->hasRole('super_admin');
    }

    /**
     * Petugas gudang hanya boleh melihat & mengirim dokumen untuk kode gudang di akunnya sendiri.
     */
    public function isWarehouseStaff(): bool
    {
        return $this->hasRole('gudang') && ! $this->hasRole('super_admin');
    }

    /**
     * Cabang Data Pengiriman yang boleh dilihat & diisi akun ini; null berarti semua cabang.
     */
    public function cabangScope(): ?string
    {
        if (blank($this->cabang) || $this->hasRole('super_admin')) {
            return null;
        }

        return trim($this->cabang);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        $path = ltrim((string) $this->avatar_url, '/');

        if (str_starts_with($path, 'storage/')) {
            return url($path);
        }

        return MediaUrl::for($path);
    }
}

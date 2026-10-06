<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * @property-read User $resource
 */
class AuthUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryRole = $this->roles?->first();

        return [
            /** ID pengguna. @var int */
            'id' => $this->id,
            /** Username login. @var string|null */
            'username' => $this->username,
            /** Nama (kolom users.name). @var string */
            'name' => $this->name,
            /** Email akun. @var string|null */
            'email' => $this->email,
            /** URL publik foto (dari kolom users.avatar_url). @var string|null */
            'avatar_url' => $this->getFilamentAvatarUrl(),
            /** Nomor WhatsApp ternormalisasi. @var string|null */
            'whatsapp_number' => $this->whatsapp_number,
            /** Peran utama: id dan name. @var array{id: int, name: string}|null */
            'role' => $primaryRole ? [
                'id' => $primaryRole->id,
                'name' => $primaryRole->name,
            ] : null,
            /** Semua nama peran. @var list<string> */
            'roles' => $this->getRoleNames()->values()->all(),
        ];
    }
}

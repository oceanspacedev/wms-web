<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryRole = $this->roles?->first();

        return [
            'id' => $this->id,
            'username' => $this->username,
            'nama_lengkap' => $this->name,
            'name' => $this->name,
            'email' => $this->email,
            'profile_photo_url' => $this->getFilamentAvatarUrl(),
            'avatar_url' => $this->avatar_url,
            'whatsapp_number' => $this->whatsapp_number,
            'role' => $primaryRole ? [
                'id' => $primaryRole->id,
                'name' => $primaryRole->name,
            ] : null,
            'roles' => $this->getRoleNames()->values()->all(),
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'avatar_url' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp,heic', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar_url.required' => 'File foto profil wajib diunggah.',
            'avatar_url.image' => 'File foto profil harus berupa gambar.',
            'avatar_url.mimes' => 'Format foto profil harus jpeg, png, jpg, webp, atau heic.',
            'avatar_url.max' => 'Ukuran foto profil maksimal 10MB.',
        ];
    }
}

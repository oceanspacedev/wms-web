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
            'profile_photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp,heic', 'max:10240'],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp,heic', 'max:10240'],
            'avatar' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,webp,heic', 'max:10240'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (
                ! $this->hasFile('profile_photo')
                && ! $this->hasFile('photo')
                && ! $this->hasFile('avatar')
            ) {
                $validator->errors()->add('profile_photo', 'File foto profil wajib diunggah.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'profile_photo.image' => 'File foto profil harus berupa gambar.',
            'profile_photo.mimes' => 'Format foto profil harus jpeg, png, jpg, webp, atau heic.',
            'profile_photo.max' => 'Ukuran foto profil maksimal 10MB.',
            'photo.image' => 'File foto profil harus berupa gambar.',
            'photo.mimes' => 'Format foto profil harus jpeg, png, jpg, webp, atau heic.',
            'photo.max' => 'Ukuran foto profil maksimal 10MB.',
            'avatar.image' => 'File foto profil harus berupa gambar.',
            'avatar.mimes' => 'Format foto profil harus jpeg, png, jpg, webp, atau heic.',
            'avatar.max' => 'Ukuran foto profil maksimal 10MB.',
        ];
    }
}

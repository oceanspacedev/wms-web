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
     * Older sam-app builds upload the photo as `profile_photo`; treat it as
     * `avatar_url` so they keep working without documenting a second field.
     */
    protected function prepareForValidation(): void
    {
        if ($this->files->has('avatar_url') || ! $this->files->has('profile_photo')) {
            return;
        }

        $this->files->set('avatar_url', $this->files->get('profile_photo'));
        $this->files->remove('profile_photo');
        $this->convertedFiles = null;
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

<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateTrackingOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Tiap badan usaha punya urutan nomor sendiri, jadi nomor SJ yang sama bisa sah ke dealer lain.
            'no_sj' => ['required', 'string', 'max:100'],
            'nama_dealer' => ['required', 'string', 'max:255'],
            'alamat_dealer' => ['nullable', 'string', 'max:1000'],
            'jumlah_value_nota' => ['nullable', 'numeric', 'min:0'],
            'tanggal_nota' => ['nullable', 'date'],
            'tanggal_pengiriman' => ['nullable', 'date'],
            'nama_pengirim' => ['nullable', 'string', 'max:255'],
            'nama_penerima' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'foto_nota_sj' => ['required', 'image', 'mimes:jpeg,png,jpg,webp,heic', 'max:12288'],
            'foto_penerima' => ['required', 'image', 'mimes:jpeg,png,jpg,webp,heic', 'max:12288'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'no_sj.required' => 'Nomor Surat Jalan wajib diisi.',
            'nama_dealer.required' => 'Nama dealer / toko tujuan wajib diisi.',
            'nama_penerima.required' => 'Nama penerima wajib diisi.',
            'foto_nota_sj.required' => 'Foto nota surat jalan wajib diunggah.',
            'foto_penerima.required' => 'Foto penerima wajib diunggah.',
            'jumlah_value_nota.numeric' => 'Nilai nota harus berupa nominal angka valid.',
            'latitude.numeric' => 'Latitude harus berupa angka koordinat valid.',
            'latitude.between' => 'Latitude harus berada dalam rentang -90 hingga 90 derajat.',
            'longitude.numeric' => 'Longitude harus berupa angka koordinat valid.',
            'longitude.between' => 'Longitude harus berada dalam rentang -180 hingga 180 derajat.',
            'foto_nota_sj.image' => 'File foto nota surat jalan harus berupa file gambar (JPG/PNG/WEBP).',
            'foto_penerima.image' => 'File foto penerima harus berupa file gambar (JPG/PNG/WEBP).',
        ];
    }
}

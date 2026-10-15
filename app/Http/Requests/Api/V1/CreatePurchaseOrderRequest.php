<?php

namespace App\Http\Requests\Api\V1;

use App\Models\PurchaseOrder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreatePurchaseOrderRequest extends FormRequest
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
        $photo = ['image', 'mimes:jpeg,png,jpg,webp,heic', 'max:12288'];

        return [
            // The warehouse knows the DO, not the PO: no_po is filled only when the DO prints it.
            'no_po' => ['nullable', 'string', 'max:100', 'unique:purchase_orders,no_po'],
            'no_do' => ['required', 'string', 'max:100', 'unique:purchase_orders,no_do'],
            'nama_supplier' => ['required', 'string', 'max:255'],
            'tanggal_do' => ['nullable', 'date'],
            'tanggal_datang' => ['nullable', 'date'],
            'nama_gudang' => ['nullable', 'string', 'max:255'],
            'alamat_gudang' => ['nullable', 'string', 'max:1000'],
            'nama_kurir_ekspedisi' => ['nullable', 'string', 'max:255'],
            'no_resi' => ['nullable', 'string', 'max:100'],
            'penerima_gudang' => ['nullable', 'string', 'max:255'],
            'qty_koli' => ['nullable', 'integer', 'min:0'],
            'qty_unit' => ['nullable', 'integer', 'min:0'],
            'keterangan_barang' => ['nullable', 'string', 'max:1000'],
            'status_penerimaan' => ['required', 'string', 'in:'.implode(',', PurchaseOrder::RECEIPT_STATUSES)],
            'catatan_gudang' => ['nullable', 'required_unless:status_penerimaan,'.PurchaseOrder::RECEIPT_COMPLETE, 'string', 'max:1000'],
            'address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'foto_do' => ['required', ...$photo],
            'foto_resi' => ['nullable', ...$photo],
            'foto_penerimaan' => ['required', ...$photo],
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
            'no_po.unique' => 'Nomor Purchase Order (PO) sudah terdaftar di sistem.',
            'no_do.required' => 'Nomor DO / surat jalan supplier wajib diisi.',
            'no_do.unique' => 'DO ini sudah pernah dicatat diterima.',
            'nama_supplier.required' => 'Nama supplier wajib diisi.',
            'status_penerimaan.required' => 'Status penerimaan wajib dipilih.',
            'status_penerimaan.in' => 'Status penerimaan harus Lengkap, Kurang, atau Rusak.',
            'catatan_gudang.required_unless' => 'Catatan wajib diisi jika barang kurang atau rusak.',
            'foto_do.required' => 'Foto DO wajib diunggah.',
            'foto_do.image' => 'File foto DO harus berupa gambar (JPG/PNG/WEBP).',
            'foto_resi.image' => 'File foto resi harus berupa gambar (JPG/PNG/WEBP).',
            'foto_penerimaan.required' => 'Foto penerimaan barang wajib diunggah.',
            'foto_penerimaan.image' => 'File foto penerimaan harus berupa gambar (JPG/PNG/WEBP).',
        ];
    }
}

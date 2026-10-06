<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @mixin PurchaseOrder
 *
 * @property-read PurchaseOrder $resource
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** ID purchase order. @var int */
            'id' => $this->id,
            /** Nomor PO. @var string */
            'no_po' => $this->no_po,
            /** Nomor SJ supplier. @var string|null */
            'no_sj_supplier' => $this->no_sj_supplier,
            /** Tanggal PO (Y-m-d). @var string|null */
            'tanggal_po' => $this->tanggal_po?->format('Y-m-d'),
            /** Tanggal datang (Y-m-d). @var string|null */
            'tanggal_datang' => $this->tanggal_datang?->format('Y-m-d'),
            /** Nama supplier. @var string */
            'nama_supplier' => $this->nama_supplier,
            /** Nama gudang penerima. @var string|null */
            'nama_gudang' => $this->nama_gudang,
            /** Alamat gudang. @var string|null */
            'alamat_gudang' => $this->alamat_gudang,
            /** Nama kurir ekspedisi. @var string|null */
            'nama_kurir_ekspedisi' => $this->nama_kurir_ekspedisi,
            /** Nomor resi. @var string|null */
            'no_resi' => $this->no_resi,
            /** Nama penerima di gudang. @var string|null */
            'penerima_gudang' => $this->penerima_gudang,
            /** Jumlah koli. @var int */
            'qty_koli' => (int) $this->qty_koli,
            /** Jumlah unit. @var int */
            'qty_unit' => (int) $this->qty_unit,
            /** Total nominal. @var float */
            'total_nominal' => (float) $this->total_nominal,
            /** Keterangan barang. @var string|null */
            'keterangan_barang' => $this->keterangan_barang,
            /** Status penerimaan gudang. @var string */
            'status_penerimaan' => $this->status_penerimaan,
            /** Catatan gudang. @var string|null */
            'catatan_gudang' => $this->catatan_gudang,
            /** URL bukti serah terima. @var string|null */
            'bukti_serah_terima_url' => $this->resolvePhotoUrl($this->bukti_serah_terima, 'purchase-orders/bukti'),
            /** Waktu dibuat (ISO 8601). @var string|null */
            'created_at' => $this->created_at?->toISOString(),
            /** Waktu diubah (ISO 8601). @var string|null */
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Resolve full accessible URL for uploaded proof photos.
     */
    protected function resolvePhotoUrl(?string $path, string $directory): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (Str::startsWith($path, 'purchase-orders/')) {
            return Storage::disk('public')->url($path);
        }

        return Storage::disk('public')->url("{$directory}/{$path}");
    }
}

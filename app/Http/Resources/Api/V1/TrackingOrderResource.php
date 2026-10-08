<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TrackingOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @mixin TrackingOrder
 *
 * @property-read TrackingOrder $resource
 */
class TrackingOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** ID surat jalan. @var int */
            'id' => $this->id,
            /** Nomor surat jalan. @var string */
            'no_sj' => $this->no_sj,
            /** Nama dealer / toko tujuan. @var string */
            'nama_dealer' => $this->nama_dealer,
            /** Alamat toko tujuan. @var string|null */
            'alamat_dealer' => $this->alamat_dealer,
            /** Nilai nota. @var float */
            'jumlah_value_nota' => (float) $this->jumlah_value_nota,
            /** Tanggal nota (Y-m-d). @var string|null */
            'tanggal_nota' => $this->tanggal_nota?->format('Y-m-d'),
            /** Tanggal pengiriman (Y-m-d). @var string|null */
            'tanggal_pengiriman' => $this->tanggal_pengiriman?->format('Y-m-d'),
            /** Nama kurir / pengirim. @var string|null */
            'nama_pengirim' => $this->nama_pengirim,
            /** Nama penerima di toko. @var string|null */
            'nama_penerima' => $this->nama_penerima,
            /** URL foto nota SJ. @var string|null */
            'foto_nota_sj_url' => $this->resolvePhotoUrl($this->foto_nota_sj, 'tracking-orders/nota'),
            /** URL foto penerima. @var string|null */
            'foto_penerima_url' => $this->resolvePhotoUrl($this->foto_penerima, 'tracking-orders/penerima'),
            /** Alamat serah terima / POD. @var string|null */
            'address' => $this->address,
            /** Koordinat latitude serah terima. @var float|null */
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            /** Koordinat longitude serah terima. @var float|null */
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            /** PENDING, IN_TRANSIT, DELIVERED, atau RETURNED. @var string */
            'status' => $this->status,
            /** Catatan kurir. @var string|null */
            'notes' => $this->notes,
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

        // If path already contains subfolder
        if (Str::startsWith($path, 'tracking-orders/')) {
            return Storage::disk('public')->url($path);
        }

        // Check if file exists directly in public disk
        if (Storage::disk('public')->exists("{$directory}/{$path}")) {
            return Storage::disk('public')->url("{$directory}/{$path}");
        }

        return Storage::disk('public')->url("{$directory}/{$path}");
    }
}

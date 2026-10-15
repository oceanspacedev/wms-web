<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory, HasOwner;

    public const RECEIPT_COMPLETE = 'Lengkap';

    /**
     * What the warehouse found on arrival; anything but complete needs a note.
     *
     * @var list<string>
     */
    public const RECEIPT_STATUSES = [self::RECEIPT_COMPLETE, 'Kurang', 'Rusak'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'no_po',
        'no_do',
        'tanggal_do',
        'tanggal_datang',
        'nama_supplier',
        'nama_gudang',
        'alamat_gudang',
        'nama_kurir_ekspedisi',
        'no_resi',
        'penerima_gudang',
        'qty_koli',
        'qty_unit',
        'total_nominal',
        'keterangan_barang',
        'status_penerimaan',
        'catatan_gudang',
        'foto_do',
        'foto_resi',
        'foto_penerimaan',
        'address',
        'latitude',
        'longitude',
        'status_verifikasi_finance',
        'catatan_finance',
        'verified_by',
        'verified_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'tanggal_do' => 'date',
            'tanggal_datang' => 'date',
            'verified_at' => 'datetime',
            'total_nominal' => 'decimal:2',
            'qty_koli' => 'integer',
            'qty_unit' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }
}

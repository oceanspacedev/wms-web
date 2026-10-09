<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CsaShipment extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'csa_import_id',
        'no_sj',
        'no_trans',
        'no_so',
        'tanggal_order',
        'tanggal_kirim',
        'badan_usaha',
        'kode_gudang',
        'nama_gudang',
        'target_sheet',
        'tujuan_dealer',
        'alamat_kirim',
        'nama_kota',
        'brand',
        'reff_note',
        'total_nominal_sj',
        'qty_unit',
        'qty_koli',
        'berat',
        'ketentuan_biaya_kirim',
        'nama_ekspedisi',
        'no_resi_awb',
        'biaya_kirim',
        'status_pembayaran',
        'status_pengiriman',
        'tanggal_diterima',
        'ket_isi_unit',
        'is_synced',
        'already_in_sheet',
        'synced_at',
        'sync_error',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tanggal_order' => 'date',
        'tanggal_kirim' => 'date',
        'tanggal_diterima' => 'date',
        'total_nominal_sj' => 'decimal:2',
        'biaya_kirim' => 'decimal:2',
        'qty_unit' => 'integer',
        'qty_koli' => 'integer',
        'berat' => 'decimal:2',
        'is_synced' => 'boolean',
        'already_in_sheet' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<CsaImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(CsaImport::class, 'csa_import_id');
    }

    /**
     * @return HasOne<TrackingOrder, $this>
     */
    public function trackingOrder(): HasOne
    {
        return $this->hasOne(TrackingOrder::class, 'no_sj', 'no_sj');
    }
}

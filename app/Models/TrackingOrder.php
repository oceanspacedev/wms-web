<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingOrder extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'no_sj',
        'nama_dealer',
        'alamat_dealer',
        'jumlah_value_nota',
        'tanggal_nota',
        'tanggal_pengiriman',
        'nama_pengirim',
        'nama_penerima',
        'foto_nota_sj',
        'foto_penerima',
        'address',
        'latitude',
        'longitude',
        'status',
        'csa_shipment_id',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tanggal_nota' => 'date',
            'tanggal_pengiriman' => 'date',
            'jumlah_value_nota' => 'decimal:2',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Get the associated CSA shipment.
     */
    public function csaShipment(): BelongsTo
    {
        return $this->belongsTo(CsaShipment::class, 'csa_shipment_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrackingOrder extends Model
{
    use HasFactory, HasOwner;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
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
            'user_id' => 'integer',
            'tanggal_nota' => 'date',
            'tanggal_pengiriman' => 'date',
            'jumlah_value_nota' => 'decimal:2',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }
}

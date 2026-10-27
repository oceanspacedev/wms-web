<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * SJ yang dikirim pada tanggal tersebut; SJ tanpa tanggal pengiriman ikut tanggal dicatat.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function onDay(Builder $query, string $date): void
    {
        $query->where(function (Builder $q) use ($date): void {
            $q->whereDate('tanggal_pengiriman', $date)
                ->orWhere(function (Builder $sub) use ($date): void {
                    $sub->whereNull('tanggal_pengiriman')
                        ->whereDate('created_at', $date);
                });
        });
    }

    /**
     * SJ dianggap terkirim begitu ada foto atau nama penerima.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function delivered(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->where(function (Builder $sub): void {
                $sub->whereNotNull('foto_penerima')->where('foto_penerima', '!=', '');
            })->orWhere(function (Builder $sub): void {
                $sub->whereNotNull('nama_penerima')->where('nama_penerima', '!=', '');
            });
        });
    }
}

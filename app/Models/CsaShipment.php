<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CsaShipment extends Model
{
    use HasFactory;

    /** Baris dari upload laporan CSA. */
    public const SOURCE_CSA = 'csa';

    /** Baris yang diinput admin langsung di web (retur, transfer internal, dll.). */
    public const SOURCE_MANUAL = 'manual';

    /** Baris hasil import file spreadsheet "Update Pengiriman" (data lama dari Google Sheet). */
    public const SOURCE_SPREADSHEET = 'spreadsheet';

    /**
     * @var array<string, string>
     */
    public const SOURCE_LABELS = [
        self::SOURCE_CSA => 'Upload CSA',
        self::SOURCE_SPREADSHEET => 'Import spreadsheet',
        self::SOURCE_MANUAL => 'Input manual',
    ];

    /**
     * Kolom yang selalu mengikuti laporan CSA terbaru saat SJ yang sama di-upload ulang.
     *
     * @var list<string>
     */
    public const CSA_FIELDS = [
        'no_trans', 'no_so', 'tanggal_order', 'tanggal_kirim', 'kode_gudang', 'nama_gudang', 'target_sheet',
        'tujuan_dealer', 'alamat_kirim', 'nama_kota', 'brand', 'reff_note', 'total_nominal_sj', 'qty_unit', 'ket_isi_unit',
    ];

    /**
     * Kolom isian admin yang juga bisa datang dari laporan CSA: hanya diisi dari upload kalau masih kosong.
     *
     * @var list<string>
     */
    public const FILL_IF_EMPTY_FIELDS = [
        'qty_koli', 'berat', 'ketentuan_biaya_kirim', 'nama_ekspedisi', 'no_resi_awb', 'biaya_kirim',
        'status_pembayaran', 'status_pengiriman',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'csa_import_id',
        'source',
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

    protected static function booted(): void
    {
        static::saving(function (CsaShipment $shipment): void {
            // Kolom angka wajib di database; dikosongkan di form berarti 0 (belum diisi)
            foreach (['total_nominal_sj', 'qty_unit', 'qty_koli', 'berat'] as $column) {
                if ($shipment->{$column} === null || $shipment->{$column} === '') {
                    $shipment->{$column} = 0;
                }
            }
        });
    }

    /**
     * Admin cabang hanya melihat cabangnya; pengguna tanpa cabang melihat semua.
     *
     * @param  Builder<CsaShipment>  $query
     * @return Builder<CsaShipment>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        $cabang = $user?->cabangScope();

        return $cabang ? $query->where('target_sheet', $cabang) : $query;
    }

    /**
     * Lead time proses (hari): tanggal order sampai tanggal kirim.
     *
     * @return Attribute<int|null, never>
     */
    protected function leadTimeProses(): Attribute
    {
        return Attribute::get(fn (): ?int => self::daysBetween($this->tanggal_order, $this->tanggal_kirim));
    }

    /**
     * Lead time kirim (hari): tanggal kirim sampai tanggal diterima.
     *
     * @return Attribute<int|null, never>
     */
    protected function leadTimeKirim(): Attribute
    {
        return Attribute::get(fn (): ?int => self::daysBetween($this->tanggal_kirim, $this->tanggal_diterima));
    }

    /**
     * Lead time keseluruhan (hari): tanggal order sampai tanggal diterima.
     *
     * @return Attribute<int|null, never>
     */
    protected function leadTimeKeseluruhan(): Attribute
    {
        return Attribute::get(fn (): ?int => self::daysBetween($this->tanggal_order, $this->tanggal_diterima));
    }

    private static function daysBetween(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }

        return (int) round($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false));
    }
}

<?php

namespace App\Models;

use App\Support\ItemName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penjualan satu barang (model + warna) di satu gudang menurut laporan penjualan CSA yang di-upload.
 */
class CsaImportItem extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'csa_import_id',
        'kode_gudang',
        'nama_gudang',
        'kode_barang',
        'nama_barang',
        'model',
        'warna',
        'brand',
        'is_unit',
        'qty',
        'nominal',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_unit' => 'boolean',
        'qty' => 'integer',
        'nominal' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<CsaImport, $this>
     */
    public function csaImport(): BelongsTo
    {
        return $this->belongsTo(CsaImport::class);
    }

    /**
     * Nama model tanpa brand, jenis, dan kode "N": "TECNO - HP 4G - CAMON 50 - CN5 8/128 - N" → "CAMON 50 CN5 8/128".
     */
    public function shortModel(): string
    {
        return ItemName::shortModel($this->model, $this->brand);
    }

    /**
     * "GUDANG MSIS SEMARANG" → "SEMARANG".
     */
    public function warehouseLabel(): string
    {
        $name = trim((string) preg_replace('/^(GUDANG\s+)?(MSIS?\s+)?/', '', strtoupper(trim((string) $this->nama_gudang))));

        return $name !== '' ? $name : $this->kode_gudang;
    }
}

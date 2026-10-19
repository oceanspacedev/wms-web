<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreightInvoiceItem extends Model
{
    use HasFactory;

    /**
     * Isi kolom "CEK TARIF" per status audit (istilah yang dipakai admin di file invoice).
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        'matched' => 'SESUAI',
        'discrepancy_rate' => 'TARIF LEBIH MAHAL',
        'discrepancy_weight' => 'BERAT LEBIH',
        'discrepancy_both' => 'BERAT & TARIF LEBIH',
        'revisi_darat' => 'REVISI DARAT',
        'missing_rate' => 'TARIF BELUM ADA',
        'missing_weight' => 'BERAT KOSONG',
        'unrecognized' => 'TIDAK DITEMUKAN',
        'duplicate' => 'DOBEL',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUS_COLORS = [
        'matched' => 'success',
        'discrepancy_rate' => 'danger',
        'discrepancy_weight' => 'danger',
        'discrepancy_both' => 'danger',
        'revisi_darat' => 'warning',
        'missing_rate' => 'gray',
        'missing_weight' => 'gray',
        'unrecognized' => 'danger',
        'duplicate' => 'danger',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'freight_invoice_id',
        'csa_shipment_id',
        'no_resi_awb',
        'no_sj',
        'origin_depo',
        'destination_city',
        'service_type',
        'billed_weight',
        'actual_weight',
        'weight_discrepancy',
        'billed_rate',
        'agreed_rate',
        'billed_insurance',
        'agreed_insurance',
        'billed_total',
        'expected_total',
        'discrepancy_amount',
        'audit_status',
        'audit_notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'billed_weight' => 'decimal:2',
        'actual_weight' => 'decimal:2',
        'weight_discrepancy' => 'decimal:2',
        'billed_rate' => 'decimal:2',
        'agreed_rate' => 'decimal:2',
        'billed_insurance' => 'decimal:2',
        'agreed_insurance' => 'decimal:2',
        'billed_total' => 'decimal:2',
        'expected_total' => 'decimal:2',
        'discrepancy_amount' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<FreightInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FreightInvoice::class, 'freight_invoice_id');
    }
}

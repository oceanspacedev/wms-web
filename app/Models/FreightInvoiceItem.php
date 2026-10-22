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
        'manual_approved_amount',
        'manual_note',
        'manual_approved_by',
        'manual_approved_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'manual_approved_amount' => 'decimal:2',
        'manual_approved_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function manualApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_approved_by');
    }

    public function isManuallyApproved(): bool
    {
        return $this->manual_approved_at !== null;
    }

    /**
     * Belum bisa dinilai (tarif rutenya belum ada di Tarif Kontrak) dan belum disetujui manual: bukan koreksi.
     * "unrecognized" hanya ada di hasil audit lama yang masih mencocokkan ke Data Pengiriman.
     */
    public function isUnverified(): bool
    {
        return in_array($this->audit_status, ['missing_rate', 'unrecognized'], true) && ! $this->isManuallyApproved();
    }

    /**
     * Nominal yang layak dibayar: hasil cek sistem, atau nominal yang disetujui admin secara manual.
     */
    public function approvedAmount(): float
    {
        if ($this->isManuallyApproved()) {
            return (float) $this->manual_approved_amount;
        }

        return $this->isUnverified() ? 0.0 : (float) $this->expected_total;
    }

    /**
     * Potongan pasti (ditagih di atas yang layak dibayar); tagihan yang belum terverifikasi tidak dihitung.
     */
    public function correctionAmount(): float
    {
        if ($this->isUnverified()) {
            return 0.0;
        }

        return round(max(0, (float) $this->billed_total - $this->approvedAmount()), 2);
    }

    /**
     * Alasan singkat kenapa resi tidak dibayar penuh (tampil di bawah kolom Selisih). Resi yang sesuai: null.
     */
    public function reason(): ?string
    {
        if ($this->isManuallyApproved()) {
            return 'Disetujui manual'.(filled($this->manual_note) ? ': '.str($this->manual_note)->limit(40) : '');
        }

        $agreed = (float) $this->agreed_rate;
        $rate = (float) $this->billed_rate > 0 ? min((float) $this->billed_rate, $agreed) : $agreed;
        // Berat yang dipakai hitungan sistem: (nominal sesuai kontrak − asuransi) ÷ tarif
        $weight = $rate > 0 ? ((float) $this->expected_total - (float) $this->billed_insurance) / $rate : 0.0;

        return match ($this->audit_status) {
            'revisi_darat' => 'Harusnya DARAT: '.self::kg($weight).' kg × Rp '.self::rupiah($agreed),
            'discrepancy_rate' => 'Tarif lebih mahal Rp '.self::rupiah((float) $this->billed_rate - $agreed).'/kg dari kontrak',
            'discrepancy_weight' => 'Berat harusnya '.self::kg($weight).' kg',
            'discrepancy_both' => 'Harusnya '.self::kg($weight).' kg × Rp '.self::rupiah($agreed),
            'missing_rate', 'unrecognized' => 'Belum terverifikasi: tarif rute belum ada',
            'missing_weight' => 'Berat belum diisi',
            'duplicate' => 'Resi sudah pernah ditagih',
            default => null,
        };
    }

    private static function kg(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }

    private static function rupiah(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    public function checkLabel(): string
    {
        return $this->isManuallyApproved() ? 'DISETUJUI MANUAL' : (self::STATUS_LABELS[$this->audit_status] ?? $this->audit_status);
    }

    public function checkColor(): string
    {
        return $this->isManuallyApproved() ? 'success' : (self::STATUS_COLORS[$this->audit_status] ?? 'gray');
    }
}

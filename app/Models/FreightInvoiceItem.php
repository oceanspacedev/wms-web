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
     * Status pencocokan baris invoice terhadap Data Pengiriman (csa_shipments).
     *
     * @var array<string, string>
     */
    public const SHIPMENT_MATCH_LABELS = [
        'matched' => 'COCOK',
        'weight_mismatch' => 'BEDA BERAT',
        'expedition_mismatch' => 'BEDA EKSPEDISI',
        'unmatched' => 'BELUM ADA',
    ];

    /**
     * @var array<string, string>
     */
    public const SHIPMENT_MATCH_COLORS = [
        'matched' => 'success',
        'weight_mismatch' => 'warning',
        'expedition_mismatch' => 'danger',
        'unmatched' => 'gray',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'freight_invoice_id',
        'csa_shipment_id',
        'no_resi_awb',
        'no_sj',
        'shipment_badan_usaha',
        'shipment_reff_note',
        'shipment_brand',
        'shipment_nominal_sj',
        'origin_depo',
        'destination_city',
        'service_type',
        'billed_weight',
        'actual_weight',
        'shipment_weight',
        'weight_discrepancy',
        'billed_rate',
        'agreed_rate',
        'billed_insurance',
        'agreed_insurance',
        'billed_total',
        'expected_total',
        'discrepancy_amount',
        'audit_status',
        'shipment_match_status',
        'shipment_matched_by',
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
        'shipment_weight' => 'decimal:2',
        'shipment_nominal_sj' => 'decimal:2',
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
     * @return BelongsTo<CsaShipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(CsaShipment::class, 'csa_shipment_id');
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
            'matched' => $this->matchedReason($agreed),
            default => null,
        };
    }

    /**
     * Kenapa tagihan dianggap sesuai, mis. "Sesuai kontrak: 14 kg × Rp 1.500".
     */
    private function matchedReason(float $agreed): string
    {
        // ECO/UDARA yang tarifnya belum ada di kontrak, tapi tidak lebih mahal dari DARAT rute yang sama
        if (str_contains((string) $this->audit_notes, 'belum ada di Tarif Kontrak')) {
            return 'Sesuai: tidak lebih mahal dari tarif DARAT';
        }

        if ((float) $this->billed_rate > 0 && (float) $this->billed_rate < $agreed - 0.5) {
            return 'Sesuai: lebih murah dari kontrak Rp '.self::rupiah($agreed).'/kg';
        }

        $reason = 'Sesuai kontrak: '.self::kg((float) $this->billed_weight).' kg × Rp '.self::rupiah($agreed);

        if ((float) $this->billed_insurance > 0) {
            $reason .= ' + asuransi Rp '.self::rupiah((float) $this->billed_insurance);
        }

        return $reason;
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

    public function shipmentMatchLabel(): string
    {
        return self::SHIPMENT_MATCH_LABELS[$this->shipment_match_status ?? 'unmatched'] ?? 'BELUM ADA';
    }

    public function shipmentMatchColor(): string
    {
        return self::SHIPMENT_MATCH_COLORS[$this->shipment_match_status ?? 'unmatched'] ?? 'gray';
    }

    public function shipmentMatchTooltip(): ?string
    {
        $parts = [];

        if (($this->shipment_match_status ?? 'unmatched') === 'unmatched') {
            $parts[] = 'Resi/SJ belum ditemukan di Data Pengiriman.';
        } elseif (($this->shipment_match_status ?? '') === 'expedition_mismatch') {
            $parts[] = 'Perhatian: Ekspedisi di catatan gudang berbeda dengan invoice!';
        } else {
            $parts[] = match ($this->shipment_matched_by) {
                'resi' => 'Cocok via No. Resi',
                'sj' => 'Cocok via No. SJ',
                default => 'Cocok Data Pengiriman',
            };
        }

        if (filled($this->shipment_badan_usaha)) {
            $parts[] = $this->shipment_badan_usaha;
        }

        if (filled($this->shipment_reff_note)) {
            $parts[] = $this->shipment_reff_note;
        }

        if (filled($this->shipment_brand)) {
            $parts[] = 'Brand: '.$this->shipment_brand;
        }

        if ($this->shipment_weight !== null && (float) $this->shipment_weight > 0) {
            $parts[] = 'Berat gudang: '.self::kg((float) $this->shipment_weight).' kg';
        }

        if ((float) $this->shipment_nominal_sj > 0) {
            $nominal = 'Nominal SJ: Rp '.self::rupiah((float) $this->shipment_nominal_sj);
            if (($ratio = $this->costRatioPercent()) !== null) {
                $nominal .= ' (ongkir '.number_format($ratio, 2, ',', '.').'%)';
            }
            $parts[] = $nominal;
        }

        return implode(' · ', $parts);
    }

    public function costRatioPercent(): ?float
    {
        $nominal = (float) $this->shipment_nominal_sj;

        if ($nominal <= 0) {
            return null;
        }

        return round(((float) $this->billed_total / $nominal) * 100, 2);
    }
}

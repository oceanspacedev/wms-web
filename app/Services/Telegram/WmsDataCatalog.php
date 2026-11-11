<?php

namespace App\Services\Telegram;

use App\Models\CsaImport;
use App\Models\CsaImportItem;
use App\Models\CsaShipment;
use App\Models\Depo;
use App\Models\Expedition;
use App\Models\ExpeditionRateCard;
use App\Models\FreightInvoice;
use App\Models\FreightInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\StockAllocation;
use App\Models\StockAllocationLine;
use App\Models\StockAllocationTransfer;
use App\Models\StockMutation;
use App\Models\TrackingOrder;
use App\Models\User;
use App\Models\WarehouseDocument;
use App\Models\WarehouseMapping;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data menu WMS yang boleh dibaca AI bot Telegram: per data hanya kolom yang aman (tanpa file, koordinat,
 * nomor HP/email pengguna, password) dan selalu lewat hak akses akun penanya.
 *
 * Tipe kolom: teks, angka, tanggal, ya_tidak, foto (dikembalikan ada/tidak), relasi (nama dari tabel lain).
 */
class WmsDataCatalog
{
    /**
     * @return array<string, array{menu: string, keterangan: string, model: class-string, izin: class-string|null, tanggal: string|null, batasi?: callable(Builder, User): void, kolom: array<string, array<string, string>>}>
     */
    public static function all(): array
    {
        return [
            'surat_jalan' => [
                'menu' => 'Surat Jalan',
                'keterangan' => 'Surat jalan yang dicatat kurir dari aplikasi saat barang diterima dealer.',
                'model' => TrackingOrder::class,
                // Tanpa izin menu: akun selain super admin otomatis hanya melihat SJ yang dicatatnya sendiri
                'izin' => null,
                'tanggal' => 'tanggal_pengiriman',
                'batasi' => fn (Builder $query, User $user) => $query->visibleTo($user),
                'kolom' => [
                    'no_sj' => ['tipe' => 'teks', 'ket' => 'Nomor SJ'],
                    'nama_dealer' => ['tipe' => 'teks'],
                    'alamat_dealer' => ['tipe' => 'teks'],
                    'jumlah_value_nota' => ['tipe' => 'angka', 'ket' => 'Nilai nota (Rp)'],
                    'tanggal_nota' => ['tipe' => 'tanggal'],
                    'tanggal_pengiriman' => ['tipe' => 'tanggal'],
                    'nama_pengirim' => ['tipe' => 'teks', 'ket' => 'Kurir'],
                    'depo' => ['tipe' => 'relasi', 'relasi' => 'depo', 'target' => 'nama', 'cari' => 'nama,kode', 'ket' => 'Depo kurir (nama atau kode, mis. CIREBON / GMCRB)'],
                    'nama_penerima' => ['tipe' => 'teks'],
                    'foto_nota_sj' => ['tipe' => 'foto'],
                    'foto_penerima' => ['tipe' => 'foto'],
                    'notes' => ['tipe' => 'teks', 'ket' => 'Catatan kurir'],
                    'created_at' => ['tipe' => 'tanggal', 'ket' => 'Dicatat'],
                ],
            ],
            'data_pengiriman' => [
                'menu' => 'Data Pengiriman',
                'keterangan' => 'Pengiriman barang ke dealer per SJ dari laporan penjualan CSA / realisasi TO (pengganti spreadsheet). Satu baris = satu SJ.',
                'model' => CsaShipment::class,
                'izin' => CsaShipment::class,
                'tanggal' => 'tanggal_kirim',
                'batasi' => fn (Builder $query, User $user) => $query->visibleTo($user),
                'kolom' => [
                    'no_sj' => ['tipe' => 'teks'],
                    'source' => ['tipe' => 'teks', 'ket' => 'Asal data: csa (laporan penjualan), to (realisasi TO), spreadsheet, manual'],
                    'no_trans' => ['tipe' => 'teks'],
                    'no_so' => ['tipe' => 'teks'],
                    'tanggal_order' => ['tipe' => 'tanggal'],
                    'tanggal_kirim' => ['tipe' => 'tanggal'],
                    'badan_usaha' => ['tipe' => 'teks'],
                    'kode_gudang' => ['tipe' => 'teks'],
                    'nama_gudang' => ['tipe' => 'teks'],
                    'target_sheet' => ['tipe' => 'teks', 'ket' => 'Cabang (mis. CIREBON, BANDUNG, JAKARTA PC)'],
                    'tujuan_dealer' => ['tipe' => 'teks'],
                    'nama_kota' => ['tipe' => 'teks'],
                    'brand' => ['tipe' => 'teks'],
                    'total_nominal_sj' => ['tipe' => 'angka', 'ket' => 'Nominal SJ (Rp)'],
                    'qty_unit' => ['tipe' => 'angka'],
                    'qty_koli' => ['tipe' => 'angka'],
                    'berat' => ['tipe' => 'angka', 'ket' => 'Berat (kg)'],
                    'ketentuan_biaya_kirim' => ['tipe' => 'teks'],
                    'nama_ekspedisi' => ['tipe' => 'teks'],
                    'nama_pengirim' => ['tipe' => 'teks'],
                    'nama_penerima' => ['tipe' => 'teks'],
                    'no_resi_awb' => ['tipe' => 'teks'],
                    'biaya_kirim' => ['tipe' => 'angka', 'ket' => 'Ongkos kirim (Rp)'],
                    'status_pembayaran' => ['tipe' => 'teks'],
                    'status_pengiriman' => ['tipe' => 'teks'],
                    'status_verifikasi_finance' => ['tipe' => 'teks'],
                    'tanggal_diterima' => ['tipe' => 'tanggal'],
                    'ket_isi_unit' => ['tipe' => 'teks', 'ket' => 'Isi unit/barang'],
                ],
            ],
            'purchase_order' => [
                'menu' => 'Purchase Order',
                'keterangan' => 'Barang masuk gudang dari supplier (PO/DO) yang dicatat petugas gudang.',
                'model' => PurchaseOrder::class,
                'izin' => null,
                'tanggal' => 'tanggal_datang',
                'batasi' => fn (Builder $query, User $user) => $query->visibleTo($user),
                'kolom' => [
                    'no_po' => ['tipe' => 'teks'],
                    'no_do' => ['tipe' => 'teks'],
                    'tanggal_do' => ['tipe' => 'tanggal'],
                    'tanggal_datang' => ['tipe' => 'tanggal'],
                    'nama_supplier' => ['tipe' => 'teks'],
                    'nama_gudang' => ['tipe' => 'teks'],
                    'nama_kurir_ekspedisi' => ['tipe' => 'teks'],
                    'no_resi' => ['tipe' => 'teks'],
                    'penerima_gudang' => ['tipe' => 'teks'],
                    'qty_koli' => ['tipe' => 'angka'],
                    'qty_unit' => ['tipe' => 'angka'],
                    'total_nominal' => ['tipe' => 'angka', 'ket' => 'Rp'],
                    'keterangan_barang' => ['tipe' => 'teks'],
                    'status_penerimaan' => ['tipe' => 'teks'],
                    'catatan_gudang' => ['tipe' => 'teks'],
                    'status_verifikasi_finance' => ['tipe' => 'teks'],
                    'foto_do' => ['tipe' => 'foto'],
                    'foto_penerimaan' => ['tipe' => 'foto'],
                ],
            ],
            'laporan_penjualan' => [
                'menu' => 'Import Penjualan CSA',
                'keterangan' => 'Daftar file laporan yang diupload: report_type "sales" = laporan penjualan CSA, "transfer" = realisasi TO.',
                'model' => CsaImport::class,
                'izin' => CsaImport::class,
                'tanggal' => 'created_at',
                'kolom' => [
                    'id' => ['tipe' => 'angka', 'ket' => 'ID laporan (dipakai di penjualan_per_barang.csa_import_id)'],
                    'file_name' => ['tipe' => 'teks'],
                    'report_type' => ['tipe' => 'teks'],
                    'period_start' => ['tipe' => 'tanggal', 'ket' => 'Awal periode laporan'],
                    'period_end' => ['tipe' => 'tanggal', 'ket' => 'Akhir periode laporan'],
                    'status' => ['tipe' => 'teks'],
                    'total_shipments' => ['tipe' => 'angka', 'ket' => 'Jumlah SJ di file'],
                    'total_new' => ['tipe' => 'angka'],
                    'total_updated' => ['tipe' => 'angka'],
                    'total_skipped' => ['tipe' => 'angka'],
                    'created_at' => ['tipe' => 'tanggal', 'ket' => 'Diupload'],
                ],
            ],
            'penjualan_per_barang' => [
                'menu' => 'Import Penjualan CSA',
                'keterangan' => 'Penjualan per gudang per barang (model + warna) dari satu laporan penjualan. Filter csa_import_id dengan id laporan (laporan terbaru = id terbesar di laporan_penjualan dengan report_type "sales").',
                'model' => CsaImportItem::class,
                'izin' => CsaImport::class,
                'tanggal' => null,
                'kolom' => [
                    'csa_import_id' => ['tipe' => 'angka'],
                    'kode_gudang' => ['tipe' => 'teks'],
                    'nama_gudang' => ['tipe' => 'teks'],
                    'kode_barang' => ['tipe' => 'teks'],
                    'nama_barang' => ['tipe' => 'teks'],
                    'model' => ['tipe' => 'teks'],
                    'warna' => ['tipe' => 'teks'],
                    'brand' => ['tipe' => 'teks'],
                    'is_unit' => ['tipe' => 'ya_tidak', 'ket' => 'true = HP/tablet, false = aksesoris & lainnya'],
                    'qty' => ['tipe' => 'angka', 'ket' => 'Unit terjual'],
                    'nominal' => ['tipe' => 'angka', 'ket' => 'Rp'],
                ],
            ],
            'dokumen_gudang' => [
                'menu' => 'Dokumen Gudang',
                'keterangan' => 'Memo/DO yang dikirim gudang ke SCM Arsip.',
                'model' => WarehouseDocument::class,
                'izin' => WarehouseDocument::class,
                'tanggal' => 'created_at',
                'batasi' => function (Builder $query, User $user): void {
                    if ($user->isWarehouseStaff()) {
                        $query->where('kode_gudang', (string) $user->kode_gudang_arsip);
                    }
                },
                'kolom' => [
                    'kode_gudang' => ['tipe' => 'teks'],
                    'po_sj_number' => ['tipe' => 'teks', 'ket' => 'Nomor PO/SJ'],
                    'program_title' => ['tipe' => 'teks'],
                    'document_type' => ['tipe' => 'teks'],
                    'original_name' => ['tipe' => 'teks', 'ket' => 'Nama file'],
                    'status' => ['tipe' => 'teks', 'ket' => 'pending, sent (terkirim), failed (gagal)'],
                    'error_message' => ['tipe' => 'teks'],
                    'sent_at' => ['tipe' => 'tanggal'],
                    'created_at' => ['tipe' => 'tanggal'],
                ],
            ],
            'alokasi_stok' => [
                'menu' => 'Alokasi Stok',
                'keterangan' => 'Satu baris per perhitungan alokasi stok (dari laporan stok ERP + penjualan).',
                'model' => StockAllocation::class,
                'izin' => StockAllocation::class,
                'tanggal' => 'stock_date',
                'kolom' => [
                    'id' => ['tipe' => 'angka'],
                    'name' => ['tipe' => 'teks'],
                    'stock_date' => ['tipe' => 'tanggal', 'ket' => 'Tanggal posisi stok'],
                    'sales_start' => ['tipe' => 'tanggal'],
                    'sales_end' => ['tipe' => 'tanggal'],
                    'target_dos' => ['tipe' => 'angka', 'ket' => 'Target stok cukup (hari)'],
                    'warehouse_count' => ['tipe' => 'angka'],
                    'sku_count' => ['tipe' => 'angka'],
                    'transfer_count' => ['tipe' => 'angka', 'ket' => 'Jumlah saran transfer'],
                    'transfer_qty' => ['tipe' => 'angka', 'ket' => 'Total unit disarankan dipindah'],
                    'po_qty_before' => ['tipe' => 'angka', 'ket' => 'Kebutuhan PO sebelum transfer'],
                    'po_qty_after' => ['tipe' => 'angka', 'ket' => 'Sisa PO setelah transfer'],
                    'created_at' => ['tipe' => 'tanggal'],
                ],
            ],
            'alokasi_stok_per_gudang' => [
                'menu' => 'Alokasi Stok (Per Gudang)',
                'keterangan' => 'Posisi stok tiap gudang × barang dalam satu alokasi. Filter stock_allocation_id (terbaru = id terbesar di alokasi_stok).',
                'model' => StockAllocationLine::class,
                'izin' => StockAllocation::class,
                'tanggal' => null,
                'kolom' => [
                    'stock_allocation_id' => ['tipe' => 'angka'],
                    'region' => ['tipe' => 'teks'],
                    'warehouse' => ['tipe' => 'teks', 'ket' => 'Kode gudang, mis. GMJKT'],
                    'sku' => ['tipe' => 'teks'],
                    'product_name' => ['tipe' => 'teks'],
                    'stock' => ['tipe' => 'angka'],
                    'incoming' => ['tipe' => 'angka', 'ket' => 'Barang di jalan'],
                    'sales_qty' => ['tipe' => 'angka', 'ket' => 'Terjual dalam periode penjualan'],
                    'sales_days' => ['tipe' => 'angka', 'ket' => 'Lama periode penjualan (hari)'],
                    'avg_daily_sales' => ['tipe' => 'angka', 'ket' => 'Laku per hari'],
                    'dos_before' => ['tipe' => 'angka', 'ket' => 'Stok cukup untuk (hari) sebelum transfer; kosong = tidak laku'],
                    'dos_after' => ['tipe' => 'angka', 'ket' => 'Stok cukup untuk (hari) setelah transfer'],
                    'status' => ['tipe' => 'teks', 'ket' => 'critical (≤7 hari), warning (≤14), healthy, overstock, no_sales'],
                    'need_qty' => ['tipe' => 'angka', 'ket' => 'Kekurangan untuk target'],
                    'transfer_in' => ['tipe' => 'angka'],
                    'transfer_out' => ['tipe' => 'angka'],
                    'po_qty' => ['tipe' => 'angka', 'ket' => 'Sisa yang perlu PO'],
                ],
            ],
            'saran_transfer' => [
                'menu' => 'Alokasi Stok (Saran Transfer)',
                'keterangan' => 'Saran pindah stok antar gudang dalam satu alokasi (stock_allocation_id).',
                'model' => StockAllocationTransfer::class,
                'izin' => StockAllocation::class,
                'tanggal' => null,
                'kolom' => [
                    'stock_allocation_id' => ['tipe' => 'angka'],
                    'region' => ['tipe' => 'teks'],
                    'sku' => ['tipe' => 'teks'],
                    'product_name' => ['tipe' => 'teks'],
                    'from_warehouse' => ['tipe' => 'teks', 'ket' => 'Gudang pengirim'],
                    'to_warehouse' => ['tipe' => 'teks', 'ket' => 'Gudang penerima'],
                    'suggested_qty' => ['tipe' => 'angka'],
                    'qty' => ['tipe' => 'angka'],
                    'status' => ['tipe' => 'teks', 'ket' => 'pending (belum disetujui), approved, rejected'],
                    'decided_at' => ['tipe' => 'tanggal'],
                ],
            ],
            'dokumen_mutasi' => [
                'menu' => 'Alokasi Stok (Dokumen Mutasi)',
                'keterangan' => 'Dokumen pengiriman stok antar gudang dari saran yang disetujui.',
                'model' => StockMutation::class,
                'izin' => StockAllocation::class,
                'tanggal' => 'created_at',
                'kolom' => [
                    'number' => ['tipe' => 'teks', 'ket' => 'Nomor dokumen, mis. MUT-2610-001'],
                    'stock_allocation_id' => ['tipe' => 'angka'],
                    'region' => ['tipe' => 'teks'],
                    'from_warehouse' => ['tipe' => 'teks'],
                    'to_warehouse' => ['tipe' => 'teks'],
                    'status' => ['tipe' => 'teks', 'ket' => 'prepared (disiapkan), shipped (dikirim), received (diterima)'],
                    'shipped_at' => ['tipe' => 'tanggal'],
                    'shipped_via' => ['tipe' => 'teks'],
                    'received_at' => ['tipe' => 'tanggal'],
                    'received_note' => ['tipe' => 'teks'],
                    'created_at' => ['tipe' => 'tanggal'],
                ],
            ],
            'ekspedisi' => [
                'menu' => 'Master Ekspedisi',
                'keterangan' => 'Daftar ekspedisi pengiriman.',
                'model' => Expedition::class,
                'izin' => Expedition::class,
                'tanggal' => null,
                'kolom' => [
                    'name' => ['tipe' => 'teks'],
                    'code' => ['tipe' => 'teks'],
                    'contact_person' => ['tipe' => 'teks'],
                    'phone' => ['tipe' => 'teks', 'ket' => 'Telepon kantor ekspedisi'],
                    'is_active' => ['tipe' => 'ya_tidak'],
                    'notes' => ['tipe' => 'teks'],
                ],
            ],
            'tarif_kontrak' => [
                'menu' => 'Tarif Kontrak (Rate Card)',
                'keterangan' => 'Tarif kontrak ekspedisi per kg dari depo asal ke kota tujuan.',
                'model' => ExpeditionRateCard::class,
                'izin' => ExpeditionRateCard::class,
                'tanggal' => null,
                'kolom' => [
                    'ekspedisi' => ['tipe' => 'relasi', 'relasi' => 'expedition', 'target' => 'name', 'cari' => 'name,code'],
                    'origin_depo' => ['tipe' => 'teks', 'ket' => 'Kota/depo asal'],
                    'destination_city' => ['tipe' => 'teks'],
                    'destination_district' => ['tipe' => 'teks'],
                    'province' => ['tipe' => 'teks'],
                    'service_type' => ['tipe' => 'teks', 'ket' => 'Layanan, mis. DARAT, UDARA, LAUT, ECO'],
                    'rate_per_kg' => ['tipe' => 'angka', 'ket' => 'Rp per kg'],
                    'min_kg' => ['tipe' => 'angka'],
                    'insurance_rate_percent' => ['tipe' => 'angka'],
                    'sla_days' => ['tipe' => 'teks', 'ket' => 'Lama kirim (hari)'],
                    'is_active' => ['tipe' => 'ya_tidak'],
                ],
            ],
            'invoice_ekspedisi' => [
                'menu' => 'Rekonsiliasi Invoice',
                'keterangan' => 'Invoice tagihan ekspedisi yang dicek terhadap tarif kontrak.',
                'model' => FreightInvoice::class,
                'izin' => FreightInvoice::class,
                'tanggal' => 'invoice_date',
                'kolom' => [
                    'id' => ['tipe' => 'angka', 'ket' => 'ID invoice (dipakai di baris_invoice_ekspedisi.freight_invoice_id)'],
                    'ekspedisi' => ['tipe' => 'relasi', 'relasi' => 'expedition', 'target' => 'name', 'cari' => 'name,code'],
                    'invoice_number' => ['tipe' => 'teks'],
                    'invoice_date' => ['tipe' => 'tanggal'],
                    'origin_city' => ['tipe' => 'teks'],
                    'period_start' => ['tipe' => 'tanggal'],
                    'period_end' => ['tipe' => 'tanggal'],
                    'total_billed_amount' => ['tipe' => 'angka', 'ket' => 'Total ditagih (Rp)'],
                    'total_approved_amount' => ['tipe' => 'angka', 'ket' => 'Total disetujui (Rp)'],
                    'total_discrepancy_amount' => ['tipe' => 'angka', 'ket' => 'Total selisih (Rp)'],
                    'total_unverified_amount' => ['tipe' => 'angka', 'ket' => 'Belum terverifikasi (Rp)'],
                    'total_items_count' => ['tipe' => 'angka'],
                    'matched_count' => ['tipe' => 'angka'],
                    'discrepancy_count' => ['tipe' => 'angka'],
                    'unrecognized_count' => ['tipe' => 'angka'],
                    'duplicate_count' => ['tipe' => 'angka'],
                    'status' => ['tipe' => 'teks'],
                ],
            ],
            'baris_invoice_ekspedisi' => [
                'menu' => 'Rekonsiliasi Invoice (rincian)',
                'keterangan' => 'Rincian per resi dari invoice ekspedisi (freight_invoice_id).',
                'model' => FreightInvoiceItem::class,
                'izin' => FreightInvoice::class,
                'tanggal' => null,
                'kolom' => [
                    'freight_invoice_id' => ['tipe' => 'angka'],
                    'no_resi_awb' => ['tipe' => 'teks'],
                    'no_sj' => ['tipe' => 'teks'],
                    'origin_depo' => ['tipe' => 'teks'],
                    'destination_city' => ['tipe' => 'teks'],
                    'service_type' => ['tipe' => 'teks'],
                    'billed_weight' => ['tipe' => 'angka', 'ket' => 'Berat ditagih (kg)'],
                    'actual_weight' => ['tipe' => 'angka', 'ket' => 'Berat sebenarnya (kg)'],
                    'billed_rate' => ['tipe' => 'angka'],
                    'agreed_rate' => ['tipe' => 'angka', 'ket' => 'Tarif kontrak'],
                    'billed_total' => ['tipe' => 'angka', 'ket' => 'Ditagih (Rp)'],
                    'expected_total' => ['tipe' => 'angka', 'ket' => 'Seharusnya (Rp)'],
                    'discrepancy_amount' => ['tipe' => 'angka', 'ket' => 'Selisih (Rp)'],
                    'audit_status' => ['tipe' => 'teks', 'ket' => 'matched (sesuai), discrepancy_rate, discrepancy_weight, discrepancy_both, revisi_darat, missing_rate, missing_weight, unrecognized (tidak ditemukan), duplicate'],
                    'audit_notes' => ['tipe' => 'teks'],
                    'manual_approved_amount' => ['tipe' => 'angka'],
                ],
            ],
            'depo' => [
                'menu' => 'Master Depo',
                'keterangan' => 'Daftar depo (kode gudang + nama).',
                'model' => Depo::class,
                'izin' => Depo::class,
                'tanggal' => null,
                'kolom' => [
                    'kode' => ['tipe' => 'teks'],
                    'nama' => ['tipe' => 'teks'],
                ],
            ],
            'pemetaan_gudang' => [
                'menu' => 'Pemetaan Gudang ke Cabang',
                'keterangan' => 'Kode gudang CSA → cabang Data Pengiriman & region alokasi stok.',
                'model' => WarehouseMapping::class,
                'izin' => WarehouseMapping::class,
                'tanggal' => null,
                'kolom' => [
                    'csa_code' => ['tipe' => 'teks'],
                    'csa_name' => ['tipe' => 'teks'],
                    'target_sheet' => ['tipe' => 'teks', 'ket' => 'Cabang'],
                    'region' => ['tipe' => 'teks'],
                    'is_active' => ['tipe' => 'ya_tidak'],
                ],
            ],
            'pengguna' => [
                'menu' => 'Users',
                'keterangan' => 'Akun pengguna WMS (tanpa nomor HP, email, atau password).',
                'model' => User::class,
                'izin' => User::class,
                'tanggal' => 'created_at',
                'kolom' => [
                    'name' => ['tipe' => 'teks'],
                    'username' => ['tipe' => 'teks'],
                    'depo' => ['tipe' => 'relasi', 'relasi' => 'depo', 'target' => 'nama', 'cari' => 'nama,kode'],
                    'cabang' => ['tipe' => 'teks', 'ket' => 'Cabang Data Pengiriman (admin cabang)'],
                    'kode_gudang_arsip' => ['tipe' => 'teks'],
                    'created_at' => ['tipe' => 'tanggal'],
                ],
            ],
        ];
    }

    /**
     * Data yang boleh dibaca akun ini (izin lihat menu di WMS).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forUser(User $user): array
    {
        return array_filter(
            self::all(),
            fn (array $dataset): bool => $dataset['izin'] === null || $user->can('viewAny', $dataset['izin']),
        );
    }

    /**
     * Ringkasan data & kolom untuk instruksi AI.
     */
    public static function describe(User $user): string
    {
        return collect(self::forUser($user))
            ->map(fn (array $dataset, string $key): string => "- {$key} (menu {$dataset['menu']}): {$dataset['keterangan']}"
                .($dataset['tanggal'] ? " Kolom tanggal utama: {$dataset['tanggal']}." : '')
                ."\n  Kolom: ".collect($dataset['kolom'])
                    ->map(fn (array $column, string $name): string => "{$name} [{$column['tipe']}]".(isset($column['ket']) ? " = {$column['ket']}" : ''))
                    ->implode('; '))
            ->implode("\n");
    }
}

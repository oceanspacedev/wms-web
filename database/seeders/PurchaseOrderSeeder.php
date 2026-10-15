<?php

namespace Database\Seeders;

use App\Models\PurchaseOrder;
use Illuminate\Database\Seeder;

class PurchaseOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $orders = [
            [
                'no_po' => 'PO-202609-0012',
                'no_do' => 'SJ-MKM-8821',
                'tanggal_do' => '2026-09-01',
                'tanggal_datang' => '2026-09-03',
                'nama_supplier' => 'PT Sumber Makmur Pratama',
                'nama_gudang' => 'GUDANG MSIS BANDUNG',
                'alamat_gudang' => 'Jl. Purnawarman, Tamansari, Bandung',
                'nama_kurir_ekspedisi' => 'J&T Cargo',
                'no_resi' => '100240387353',
                'penerima_gudang' => 'Ahmad Suhendra',
                'qty_koli' => 15,
                'qty_unit' => 150,
                'total_nominal' => 45000000,
                'keterangan_barang' => 'Unit Smartphone & Tablet Tecno batch Sept-1',
                'status_penerimaan' => 'Lengkap',
                'catatan_gudang' => 'Kondisi segel utuh, kardus aman tidak basah.',
                'status_verifikasi_finance' => 'Disetujui',
                'catatan_finance' => 'Faktur dan SJ fisik sesuai PO.',
                'verified_by' => 'Nurul Hidayah',
                'verified_at' => '2026-09-04 10:15:00',
            ],
            [
                'no_po' => 'PO-202609-0025',
                'no_do' => 'SJ-ELK-4412',
                'tanggal_do' => '2026-09-05',
                'tanggal_datang' => '2026-09-08',
                'nama_supplier' => 'PT Citra Elektronik Nusantara',
                'nama_gudang' => 'GUDANG MSIS CIREBON',
                'alamat_gudang' => 'Kawasan Industri Cirebon Barat',
                'nama_kurir_ekspedisi' => 'SiCepat Cargo',
                'no_resi' => '002938172611',
                'penerima_gudang' => 'Rahmat Hidayat',
                'qty_koli' => 8,
                'qty_unit' => 80,
                'total_nominal' => 28500000,
                'keterangan_barang' => 'Aksesoris Charger & Earphone Original',
                'status_penerimaan' => 'Lengkap',
                'catatan_gudang' => 'Diterima dalam kondisi baik.',
                'status_verifikasi_finance' => 'Menunggu Pemeriksaan',
                'catatan_finance' => null,
                'verified_by' => null,
                'verified_at' => null,
            ],
            [
                'no_po' => 'PO-202609-0041',
                'no_do' => 'SJ-DIST-9901',
                'tanggal_do' => '2026-09-10',
                'tanggal_datang' => '2026-09-14',
                'nama_supplier' => 'PT Mitra Distribusi Mandiri',
                'nama_gudang' => 'GUDANG MSIS JAKARTA PIK',
                'alamat_gudang' => 'Ruko Cordoba Blok D No. 12, Pantai Indah Kapuk',
                'nama_kurir_ekspedisi' => 'JNE Trucking',
                'no_resi' => 'JNE-TRK-771822',
                'penerima_gudang' => 'Bambang Irawan',
                'qty_koli' => 20,
                'qty_unit' => 200,
                'total_nominal' => 82000000,
                'keterangan_barang' => 'Stock Unit Handphone Baru',
                'status_penerimaan' => 'Kurang',
                'catatan_gudang' => 'Terdapat selisih 2 koli masih dalam proses klaim ekspedisi.',
                'status_verifikasi_finance' => 'Menunggu Pemeriksaan',
                'catatan_finance' => 'Menunggu BA klaim kekurangan barang dari logistik.',
                'verified_by' => null,
                'verified_at' => null,
            ],
        ];

        foreach ($orders as $order) {
            PurchaseOrder::updateOrCreate(
                ['no_po' => $order['no_po']],
                $order
            );
        }
    }
}

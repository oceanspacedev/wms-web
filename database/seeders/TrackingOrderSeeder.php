<?php

namespace Database\Seeders;

use App\Models\TrackingOrder;
use Illuminate\Database\Seeder;

class TrackingOrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $orders = [
            [
                'no_sj' => '2401304519',
                'nama_dealer' => 'PT BCA',
                'alamat_dealer' => 'Jl. Ibu Inggit Garnasih',
                'jumlah_value_nota' => 0,
                'tanggal_nota' => '2024-01-30',
                'tanggal_pengiriman' => '2024-01-30',
                'nama_pengirim' => 'Heidy',
                'nama_penerima' => 'Imas',
                'foto_nota_sj' => 'scaled_c02a6555-d230-49e7-8069-43275fe1ee662732127237249734592.jpg',
                'foto_penerima' => '20240130_142636.jpg',
                'address' => 'Jalan Ibu Inggit Garnasih, Ciateul, Bandung City, West Java, Indonesia',
            ],
            [
                'no_sj' => '2401304461',
                'nama_dealer' => 'Mega Lestari Jaya',
                'alamat_dealer' => 'BEC (Bandung Electronic Center)',
                'jumlah_value_nota' => 0,
                'tanggal_nota' => '2024-01-30',
                'tanggal_pengiriman' => '2024-01-30',
                'nama_pengirim' => 'Tomy',
                'nama_penerima' => 'Rini',
                'foto_nota_sj' => 'scaled_35c0bf62-2104-4e0d-ae3a-4aff493fdb1b9083617217951705276.jpg',
                'foto_penerima' => 'MEGA LESTARI JAYA_30012024_162203.jpg',
                'address' => 'Jl. Purnawarman, Tamansari, Bandung City, West Java',
            ],
            [
                'no_sj' => '2401304502',
                'nama_dealer' => 'Sarana Komunika',
                'alamat_dealer' => 'Jl. Istiqomah No.42, Lembang, Kec. Lembang, Kabupaten Bandung Barat, Jawa Barat 40391',
                'jumlah_value_nota' => 0,
                'tanggal_nota' => '2024-01-30',
                'tanggal_pengiriman' => '2024-01-30',
                'nama_pengirim' => 'Tomy',
                'nama_penerima' => 'Dini',
                'foto_nota_sj' => 'scaled_21fa39a4-f2af-4013-8536-ad0f3fa4e04f7085142680156380156.jpg',
                'foto_penerima' => 'SARANA KOMUNIKA_30012024_165510.jpg',
                'address' => 'Jl. Istiqomah No.42, Lembang, Kec. Lembang, Kabupaten Bandung Barat',
            ],
            [
                'no_sj' => '2401304508',
                'nama_dealer' => 'Ahmad Store',
                'alamat_dealer' => 'Jalan Sapujagat Jalan Sadang Serang No.53, RT.3/RW.9, Sukaluyu, Bandung City, West Java, Indonesia',
                'jumlah_value_nota' => 0,
                'tanggal_nota' => '2024-01-30',
                'tanggal_pengiriman' => '2024-01-30',
                'nama_pengirim' => 'Tomy',
                'nama_penerima' => 'Syifa',
                'foto_nota_sj' => 'scaled_bcc70c98-49f8-44a1-b902-b4ca0ed2fd8a756744496089512706.jpg',
                'foto_penerima' => 'ahmad store_30012024_155440.jpg',
                'address' => 'Jalan Sapujagat Jalan Sadang Serang No.53, RT.3/RW.9, Sukaluyu, Bandung City, West Java, Indonesia',
            ],
            [
                'no_sj' => '2401304528',
                'nama_dealer' => 'Levin',
                'alamat_dealer' => 'Ruko Pasar Prambanan',
                'jumlah_value_nota' => 1308000,
                'tanggal_nota' => '2024-01-30',
                'tanggal_pengiriman' => '2024-01-30',
                'nama_pengirim' => 'Fauzan',
                'nama_penerima' => 'Rianto',
                'foto_nota_sj' => 'IMG-20240131-WA0002.jpg',
                'foto_penerima' => 'IMG-20240130-WA0007.jpg',
                'address' => 'Jalan Pasar Prambanan, Kecamatan Prambanan',
            ],
            [
                'no_sj' => '1062',
                'nama_dealer' => 'Auto EV Cisoka dan Tigaraksa',
                'alamat_dealer' => 'Cisoka',
                'jumlah_value_nota' => 11,
                'tanggal_nota' => '2024-01-27',
                'tanggal_pengiriman' => '2024-01-30',
                'nama_pengirim' => 'Komarudin',
                'nama_penerima' => 'Rofik',
                'foto_nota_sj' => 'scaled_ceeacbaa-1fde-4e6a-bd4e-1951dab6f54a5281254191116434164.jpg',
                'foto_penerima' => 'scaled_abaebbf-c73d-427d-bc26-bf16d8e230ee656717883945564686.jpg',
                'address' => 'Jl. Raya Cisoka, Adiyasa no 14 RT : 002 RW : 005 Kelurahan : Cikasungka, Kec. Solear',
            ],
            [
                'no_sj' => '2401304455',
                'nama_dealer' => 'GMS Cell',
                'alamat_dealer' => 'Plumbon',
                'jumlah_value_nota' => 0,
                'tanggal_nota' => '2024-01-30',
                'tanggal_pengiriman' => '2024-01-30',
                'nama_pengirim' => 'Adityo',
                'nama_penerima' => 'Siska',
                'foto_nota_sj' => 'IMG_20240130_185757.jpg',
                'foto_penerima' => 'IMG_20240130_185731.jpg',
                'address' => 'Kisabalanang, Marikangen, Kec. Plumbon, Kabupaten Cirebon',
            ],
        ];

        foreach ($orders as $order) {
            TrackingOrder::updateOrCreate(
                ['no_sj' => $order['no_sj']],
                $order
            );
        }
    }
}

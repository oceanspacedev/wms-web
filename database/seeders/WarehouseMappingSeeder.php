<?php

namespace Database\Seeders;

use App\Models\WarehouseMapping;
use Illuminate\Database\Seeder;

class WarehouseMappingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $mappings = [
            ['csa_code' => 'GMCRB', 'csa_name' => 'GUDANG MSIS CIREBON', 'target_sheet' => 'CIREBON'],
            ['csa_code' => 'GMBDG', 'csa_name' => 'GUDANG MSIS BANDUNG', 'target_sheet' => 'BANDUNG'],
            ['csa_code' => 'WMONL', 'csa_name' => 'GUDANG ONLINE PIK', 'target_sheet' => 'JAKARTA PIK'],
            ['csa_code' => 'GMJKT', 'csa_name' => 'GUDANG MSIS JAKARTA', 'target_sheet' => 'JAKARTA PC'],
            ['csa_code' => 'GBO', 'csa_name' => 'GUDANG BANDAR ONLINE', 'target_sheet' => 'JAKARTA PIK'],
            ['csa_code' => 'GMPWT', 'csa_name' => 'GUDANG MSIS PURWOKERTO', 'target_sheet' => 'PURWOKERTO'],
            ['csa_code' => 'GMSBY', 'csa_name' => 'GUDANG MSIS SURABAYA', 'target_sheet' => 'SURABAYA'],
            ['csa_code' => 'GMSMG', 'csa_name' => 'GUDANG MSIS SEMARANG', 'target_sheet' => 'SEMARANG'],
            ['csa_code' => 'GMMKS', 'csa_name' => 'GUDANG MSI MAKASSAR', 'target_sheet' => 'MAKASSAR'],
            ['csa_code' => 'GMMDO', 'csa_name' => 'GUDANG MSI MANADO', 'target_sheet' => 'MANADO'],
            ['csa_code' => 'GMPLU', 'csa_name' => 'GUDANG MSI PALU', 'target_sheet' => 'PALU'],
            ['csa_code' => 'GMPDG', 'csa_name' => 'GUDANG PADANG', 'target_sheet' => 'PADANG'],
            ['csa_code' => 'GMPKU', 'csa_name' => 'GUDANG PEKANBARU', 'target_sheet' => 'PEKANBARU'],
            ['csa_code' => 'GMPLB', 'csa_name' => 'GUDANG PALEMBANG', 'target_sheet' => 'PALEMBANG'],
            ['csa_code' => 'GMMDN', 'csa_name' => 'GUDANG MSI MEDAN', 'target_sheet' => 'MEDAN'],
            ['csa_code' => 'GMJMB', 'csa_name' => 'GUDANG JAMBI', 'target_sheet' => 'JAMBI'],
            ['csa_code' => 'GMBKL', 'csa_name' => 'GUDANG BENGKULU', 'target_sheet' => 'BENGKULU'],
            ['csa_code' => 'GMLMP', 'csa_name' => 'GUDANG LAMPUNG', 'target_sheet' => 'LAMPUNG'],
            ['csa_code' => 'RETSYS', 'csa_name' => 'GUDANG RETUR SYSTEM ONLINE', 'target_sheet' => 'RETUR'],
            ['csa_code' => 'RF', 'csa_name' => 'GUDANG REFUND', 'target_sheet' => 'RETUR'],
        ];

        // Region Alokasi Stok: PUSAT terhubung ke semua region; gudang tanpa region tidak ikut
        $regions = [
            'GMJKT' => 'PUSAT',
            'GMBDG' => 'JAWA BARAT', 'GMCRB' => 'JAWA BARAT',
            'GMSMG' => 'JAWA TENGAH', 'GMPWT' => 'JAWA TENGAH',
            'GMSBY' => 'JAWA TIMUR',
            'GMMDN' => 'SUMATERA UTARA & TENGAH', 'GMPKU' => 'SUMATERA UTARA & TENGAH', 'GMPDG' => 'SUMATERA UTARA & TENGAH',
            'GMJMB' => 'SUMATERA SELATAN', 'GMPLB' => 'SUMATERA SELATAN', 'GMBKL' => 'SUMATERA SELATAN', 'GMLMP' => 'SUMATERA SELATAN',
            'GMMKS' => 'SULAWESI', 'GMMDO' => 'SULAWESI', 'GMPLU' => 'SULAWESI',
        ];

        foreach ($mappings as $mapping) {
            WarehouseMapping::updateOrCreate(
                ['csa_code' => $mapping['csa_code']],
                [
                    'csa_name' => $mapping['csa_name'],
                    'target_sheet' => $mapping['target_sheet'],
                    'region' => $regions[$mapping['csa_code']] ?? null,
                    'is_active' => true,
                ]
            );
        }
    }
}

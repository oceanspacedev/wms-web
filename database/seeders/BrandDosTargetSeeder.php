<?php

namespace Database\Seeders;

use App\Models\BrandDosTarget;
use Illuminate\Database\Seeder;

class BrandDosTargetSeeder extends Seeder
{
    /**
     * Master Target DOS per brand sesuai standar Monitoring DOS.
     */
    public function run(): void
    {
        $rows = [
            // PT. MSI
            ['company' => 'PT. MSI', 'brand' => 'TECNO', 'label' => 'TECNO ONLINE', 'segment' => BrandDosTarget::SEGMENT_ONLINE, 'region_scope' => null, 'target_dos' => 20],
            ['company' => 'PT. MSI', 'brand' => 'TECNO', 'label' => 'TECNO OFFLINE', 'segment' => BrandDosTarget::SEGMENT_OFFLINE, 'region_scope' => null, 'target_dos' => 35],
            ['company' => 'PT. MSI', 'brand' => 'MOTOROLA', 'label' => 'MOTOROLA', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => null, 'target_dos' => 45],
            ['company' => 'PT. MSI', 'brand' => 'MOTOPAD', 'label' => 'MOTOPAD', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => null, 'target_dos' => 60],
            ['company' => 'PT. MSI', 'brand' => 'ITEL', 'label' => 'ITEL', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => null, 'target_dos' => 40],
            ['company' => 'PT. MSI', 'brand' => 'VILLAON', 'label' => 'VILLAON', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => null, 'target_dos' => 40],
            ['company' => 'PT. MSI', 'brand' => 'IPHONE', 'label' => 'IPHONE', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => null, 'target_dos' => 45],
            ['company' => 'PT. MSI', 'brand' => 'INFINIX', 'label' => 'INFINIX', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => null, 'target_dos' => null],
            ['company' => 'PT. MSI', 'brand' => 'SAMSUNG', 'label' => 'SAMSUNG', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => null, 'target_dos' => 30],

            // CV. TOP
            ['company' => 'CV. TOP', 'brand' => 'REALME', 'label' => 'REALME WJ', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => 'JAWA BARAT', 'target_dos' => 40],
            ['company' => 'CV. TOP', 'brand' => 'REALME', 'label' => 'REALME CJ', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => 'JAWA TENGAH', 'target_dos' => 40],
            ['company' => 'CV. TOP', 'brand' => 'SAMSUNG', 'label' => 'SAMSUNG', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => null, 'target_dos' => 30],

            // PT. SMI
            ['company' => 'PT. SMI', 'brand' => 'MINIMO', 'label' => 'MINIMO', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => null, 'target_dos' => null],
            ['company' => 'PT. SMI', 'brand' => 'RAPA', 'label' => 'RAPA', 'segment' => BrandDosTarget::SEGMENT_ALL, 'region_scope' => null, 'target_dos' => null],
            ['company' => 'PT. SMI', 'brand' => 'ORAIMO', 'label' => 'ORAIMO', 'segment' => BrandDosTarget::SEGMENT_OFFLINE, 'region_scope' => null, 'target_dos' => null],
            ['company' => 'PT. SMI', 'brand' => 'ORAIMO', 'label' => 'ORAIMO ONLINE', 'segment' => BrandDosTarget::SEGMENT_ONLINE, 'region_scope' => null, 'target_dos' => null],
        ];

        foreach ($rows as $row) {
            BrandDosTarget::updateOrCreate(
                ['company' => $row['company'], 'label' => $row['label']],
                [
                    'brand' => $row['brand'],
                    'segment' => $row['segment'],
                    'region_scope' => $row['region_scope'],
                    'target_dos' => $row['target_dos'],
                    'is_active' => true,
                ],
            );
        }
    }
}

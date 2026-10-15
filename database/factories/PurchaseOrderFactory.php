<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'no_po' => 'PO-'.fake()->numerify('202609-####'),
            'no_do' => 'SJ-SUP-'.fake()->numerify('#####'),
            'tanggal_do' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'tanggal_datang' => fake()->dateTimeBetween('-2 weeks', 'now')->format('Y-m-d'),
            'nama_supplier' => fake()->company(),
            'nama_gudang' => fake()->randomElement(['GUDANG MSIS BANDUNG', 'GUDANG MSIS CIREBON', 'GUDANG MSIS JAKARTA PIK']),
            'alamat_gudang' => fake()->address(),
            'nama_kurir_ekspedisi' => fake()->randomElement(['J&T Cargo', 'JNE Trucking', 'SiCepat Cargo', 'Armada Distributor']),
            'no_resi' => fake()->numerify('100240######'),
            'penerima_gudang' => fake()->name(),
            'qty_koli' => fake()->numberBetween(1, 20),
            'qty_unit' => fake()->numberBetween(10, 500),
            'total_nominal' => fake()->numberBetween(5_000_000, 150_000_000),
            'keterangan_barang' => fake()->sentence(),
            'status_penerimaan' => fake()->randomElement(['Lengkap', 'Kurang', 'Rusak']),
            'catatan_gudang' => 'Pemeriksaan barang masuk gudang',
            'foto_do' => null,
            'status_verifikasi_finance' => fake()->randomElement(['Menunggu Pemeriksaan', 'Disetujui', 'Ditolak']),
            'catatan_finance' => null,
            'verified_by' => fake()->name(),
            'verified_at' => now(),
        ];
    }
}

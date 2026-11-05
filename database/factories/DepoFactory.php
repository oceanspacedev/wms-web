<?php

namespace Database\Factories;

use App\Models\Depo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Depo>
 */
class DepoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => 'GM'.fake()->unique()->lexify('???'),
            'nama' => fake()->unique()->city(),
        ];
    }
}

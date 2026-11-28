<?php

namespace App\Models;

use App\Services\FreightRateGoogleSheetService;
use Database\Factories\TariffSearchHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TariffSearchHistory extends Model
{
    /** @use HasFactory<TariffSearchHistoryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'origin',
        'destination',
        'district',
        'weight',
        'service',
        'total_results',
        'cheapest_expedition',
        'cheapest_cost',
        'rates_summary',
        'total_amount',
        'store_name',
        'item_type',
        'destination_address',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'total_amount' => 'float',
            'cheapest_cost' => 'float',
            'total_results' => 'integer',
            'rates_summary' => 'array',
        ];
    }

    /**
     * @return array<int, array{origin?: string, destination?: string, expedition: string, service: string, total_cost: float|int}>
     */
    public function getRatesList(): array
    {
        if (! empty($this->rates_summary) && is_array($this->rates_summary)) {
            return $this->rates_summary;
        }

        try {
            /** @var FreightRateGoogleSheetService $service */
            $service = app(FreightRateGoogleSheetService::class);
            $origins = array_values(array_filter(array_map('trim', explode(',', (string) $this->origin))));
            $destinations = array_values(array_filter(array_map('trim', explode(',', (string) $this->destination))));
            $summary = [];

            foreach ($origins as $origin) {
                foreach ($destinations as $destination) {
                    $rates = $service->compareRates($origin, $destination, $this->weight, $this->service);
                    foreach ($rates as $r) {
                        $summary[] = [
                            'origin' => $origin,
                            'destination' => $destination,
                            'expedition' => (string) $r['expedition'],
                            'service' => (string) $r['service'],
                            'total_cost' => $r['total_cost'],
                        ];
                    }
                }
            }

            if ($summary !== []) {
                usort($summary, fn (array $a, array $b): int => $a['total_cost'] <=> $b['total_cost']);
                $this->update(['rates_summary' => $summary]);

                return $summary;
            }
        } catch (\Throwable) {
            // fallback
        }

        if (! empty($this->cheapest_expedition)) {
            return [
                [
                    'expedition' => $this->cheapest_expedition,
                    'service' => $this->service ?: 'DARAT',
                    'total_cost' => (float) $this->cheapest_cost,
                ],
            ];
        }

        return [];
    }
}

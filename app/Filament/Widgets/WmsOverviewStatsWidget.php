<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\CheckTariff;
use App\Filament\Resources\CsaShipments\CsaShipmentResource;
use App\Filament\Resources\ExpeditionRateCards\ExpeditionRateCardResource;
use App\Filament\Resources\TrackingOrders\TrackingOrderResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Expedition;
use App\Models\ExpeditionRateCard;
use App\Models\TrackingOrder;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class WmsOverviewStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->hasRole('super_admin')
            || $user->can('ViewAny:TrackingOrder')
            || $user->can('ViewAny:CsaShipment')
            || $user->can('ViewAny:ExpeditionRateCard')
            || $user->can('View:CheckTariff')
            || $user->can('ViewAny:User');
    }

    /**
     * @return int|array<string, ?int>
     */
    protected function getColumns(): int|array
    {
        $count = count($this->getCachedStats());

        return match (true) {
            $count >= 4 => ['md' => 2, 'xl' => 4],
            $count === 3 => ['md' => 3],
            $count === 2 => ['md' => 2],
            default => 1,
        };
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $stats = [];
        $isSuperAdmin = $user->hasRole('super_admin');

        if ($isSuperAdmin || $user->can('ViewAny:TrackingOrder')) {
            $stats[] = $this->trackingOrderStat();
        }

        if ($isSuperAdmin || $user->can('ViewAny:CsaShipment')) {
            $stats[] = $this->dataPengirimanStat($user);
        }

        if ($isSuperAdmin || $user->can('ViewAny:ExpeditionRateCard') || $user->can('View:CheckTariff')) {
            $stats[] = $this->rateCardStat($isSuperAdmin || $user->can('ViewAny:ExpeditionRateCard'));
        }

        if ($isSuperAdmin || $user->can('ViewAny:User')) {
            $stats[] = $this->activeUsersStat();
        }

        return $stats;
    }

    private function trackingOrderStat(): Stat
    {
        $today = Carbon::today('Asia/Jakarta')->toDateString();
        $baseQuery = fn (): Builder => TrackingOrderResource::getEloquentQuery();

        $todayCount = (clone $baseQuery())->onDay($today)->count();
        $todayDelivered = (clone $baseQuery())->onDay($today)->delivered()->count();
        $todayInTransit = max(0, $todayCount - $todayDelivered);

        $url = TrackingOrderResource::getUrl();
        $trend = $this->sevenDayTrackingTrend($baseQuery);

        $todayStat = Stat::make('Surat Jalan Hari Ini', $this->animatedNumber($todayCount))
            ->description($todayCount > 0
                ? number_format($todayDelivered, 0, ',', '.').' terkirim · '.number_format($todayInTransit, 0, ',', '.').' di jalan'
                : 'Belum ada hari ini')
            ->color($todayCount > 0 ? 'success' : 'gray')
            ->url($url);

        if (array_sum($trend) > 0) {
            $todayStat->chart($trend);
        }

        return $todayStat;
    }

    private function dataPengirimanStat(User $user): Stat
    {
        $scopeKey = $user->cabangScope() ?: 'all';

        /** @var array{total: int, without_resi: int} $summary */
        $summary = Cache::remember("dashboard:data_pengiriman_summary:{$scopeKey}", 60, function (): array {
            $row = CsaShipmentResource::getEloquentQuery()
                ->selectRaw("COUNT(*) as total, SUM(CASE WHEN no_resi_awb IS NULL OR no_resi_awb = '' THEN 1 ELSE 0 END) as without_resi")
                ->first();

            return [
                'total' => (int) ($row?->total ?? 0),
                'without_resi' => (int) ($row?->without_resi ?? 0),
            ];
        });

        $total = $summary['total'];
        $withoutResi = $summary['without_resi'];

        $description = match (true) {
            $total === 0 => 'Belum ada data',
            $withoutResi > 0 => number_format($withoutResi, 0, ',', '.').' belum ada resi',
            default => 'Semua resi lengkap',
        };

        $color = match (true) {
            $total === 0 => 'gray',
            $withoutResi > 0 => 'warning',
            default => 'success',
        };

        return Stat::make('Data Pengiriman', $this->animatedNumber($total))
            ->description($description)
            ->color($color)
            ->url(CsaShipmentResource::getUrl());
    }

    private function rateCardStat(bool $canViewRateCards): Stat
    {
        $version = ExpeditionRateCard::routeCacheVersion();

        /** @var array{routes: int, expeditions: int} $summary */
        $summary = Cache::remember("dashboard:rate_cards_summary:v{$version}", 3600, function (): array {
            return [
                'routes' => ExpeditionRateCard::where('is_active', true)->count(),
                'expeditions' => Expedition::where('is_active', true)->count(),
            ];
        });

        $url = $canViewRateCards
            ? ExpeditionRateCardResource::getUrl()
            : CheckTariff::getUrl();

        return Stat::make('Tarif Kontrak', $this->animatedNumber($summary['routes']))
            ->description(number_format($summary['expeditions'], 0, ',', '.').' ekspedisi aktif')
            ->color('info')
            ->url($url);
    }

    private function activeUsersStat(): Stat
    {
        $totalUsers = User::query()->count();
        $activeUsers = User::query()
            ->where(function (Builder $query): void {
                $query->whereNotNull('email_verified_at')
                    ->orWhereNotNull('whatsapp_verified_at');
            })
            ->count();
        $whatsappUsers = User::query()
            ->whereNotNull('whatsapp_number')
            ->where('whatsapp_number', '!=', '')
            ->count();

        return Stat::make('User Aktif', $this->animatedNumber($activeUsers))
            ->description('dari '.number_format($totalUsers, 0, ',', '.').' akun · '.number_format($whatsappUsers, 0, ',', '.').' WA')
            ->color('primary')
            ->url(UserResource::getUrl());
    }

    /**
     * Animasi hitung naik (count-up) dari 0 ke angka target memakai Alpine.js + ease-out cubic.
     */
    private function animatedNumber(int $target): HtmlString
    {
        $formatted = number_format($target, 0, ',', '.');

        if ($target <= 0) {
            return new HtmlString('<span style="font-variant-numeric: tabular-nums;">'.$formatted.'</span>');
        }

        $duration = $target >= 1000 ? 950 : 650;

        return new HtmlString(sprintf(
            '<span style="font-variant-numeric: tabular-nums;" x-data="{ target: %d, current: 0, fmt(v) { return new Intl.NumberFormat(\'id-ID\').format(v); }, init() { if (window.matchMedia(\'(prefers-reduced-motion: reduce)\').matches) { this.current = this.target; return; } const duration = %d; const start = performance.now(); const tick = (now) => { const p = Math.min((now - start) / duration, 1); const eased = 1 - Math.pow(1 - p, 3); this.current = Math.round(this.target * eased); if (p < 1) { requestAnimationFrame(tick); } else { this.current = this.target; } }; requestAnimationFrame(tick); } }" x-text="fmt(current)">%s</span>',
            $target,
            $duration,
            $formatted,
        ));
    }

    /**
     * Tren jumlah SJ 7 hari terakhir (termasuk hari ini) dalam satu query.
     *
     * @param  callable(): Builder<TrackingOrder>  $baseQuery
     * @return list<int>
     */
    private function sevenDayTrackingTrend(callable $baseQuery): array
    {
        $end = Carbon::today('Asia/Jakarta');
        $start = $end->copy()->subDays(6);

        $rows = $baseQuery()
            ->where(function (Builder $query) use ($start, $end): void {
                $query->whereBetween('tanggal_pengiriman', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function (Builder $sub) use ($start, $end): void {
                        $sub->whereNull('tanggal_pengiriman')
                            ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]);
                    });
            })
            ->get(['tanggal_pengiriman', 'created_at']);

        $countsByDate = [];
        foreach ($rows as $row) {
            $date = $row->tanggal_pengiriman?->toDateString()
                ?? $row->created_at?->timezone('Asia/Jakarta')->toDateString();

            if ($date !== null) {
                $countsByDate[$date] = ($countsByDate[$date] ?? 0) + 1;
            }
        }

        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $key = $end->copy()->subDays($i)->toDateString();
            $series[] = $countsByDate[$key] ?? 0;
        }

        return $series;
    }
}

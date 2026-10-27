<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Http\Resources\Api\V1\TrackingOrderResource;
use App\Models\PurchaseOrder;
use App\Models\TrackingOrder;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Beranda', 'Ringkasan halaman beranda aplikasi WMS.', 5)]
class HomeApiController extends Controller
{
    /** Beranda hanya menampilkan cuplikan; daftar lengkap ada di halaman SJ dan PO. */
    private const DEFAULT_LIMIT = 5;

    private const MAX_LIMIT = 20;

    #[Endpoint(
        title: 'Beranda hari ini',
        description: 'Ringkasan SJ dan PO hari ini (zona waktu server) milik user yang login (super admin melihat semua). `tracking_orders`: total, delivered, pending, dan items terbaru (TrackingOrderResource). `purchase_orders`: total, complete, short, damaged, dan items terbaru (PurchaseOrderResource). SJ ikut tanggal_pengiriman, PO ikut tanggal_datang; data tanpa tanggal ikut tanggal dicatat.',
    )]
    #[QueryParameter('limit', 'Jumlah item terbaru per jenis, default 5, maksimum 20.', type: 'integer')]
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now()->toDateString();
        $limit = max(1, min($request->integer('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT));

        $trackingOrders = TrackingOrder::query()->visibleTo($user)->onDay($today);
        $trackingTotal = (clone $trackingOrders)->count();
        $trackingDelivered = (clone $trackingOrders)->delivered()->count();

        $purchaseOrders = PurchaseOrder::query()->visibleTo($user)->onDay($today);
        $statusCounts = (clone $purchaseOrders)
            ->selectRaw('status_penerimaan, count(*) as aggregate')
            ->groupBy('status_penerimaan')
            ->pluck('aggregate', 'status_penerimaan');

        return response()->json([
            'success' => true,
            'message' => 'Ringkasan beranda hari ini berhasil dimuat.',
            'data' => [
                'date' => $today,
                'tracking_orders' => [
                    'total' => $trackingTotal,
                    'delivered' => $trackingDelivered,
                    'pending' => max(0, $trackingTotal - $trackingDelivered),
                    'items' => TrackingOrderResource::collection(
                        $trackingOrders->latest('id')->limit($limit)->get()
                    ),
                ],
                'purchase_orders' => [
                    'total' => (int) $statusCounts->sum(),
                    'complete' => (int) ($statusCounts[PurchaseOrder::RECEIPT_COMPLETE] ?? 0),
                    'short' => (int) ($statusCounts['Kurang'] ?? 0),
                    'damaged' => (int) ($statusCounts['Rusak'] ?? 0),
                    'items' => PurchaseOrderResource::collection(
                        $purchaseOrders->latest('id')->limit($limit)->get()
                    ),
                ],
            ],
        ]);
    }
}

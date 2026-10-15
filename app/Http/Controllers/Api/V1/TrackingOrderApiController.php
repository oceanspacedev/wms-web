<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateTrackingOrderRequest;
use App\Http\Resources\Api\V1\TrackingOrderResource;
use App\Models\TrackingOrder;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Surat Jalan', 'Surat jalan yang dicatat kurir sekali jalan di lokasi penerimaan.', 3)]
class TrackingOrderApiController extends Controller
{
    #[Endpoint(
        title: 'Buat surat jalan baru',
        description: 'Kurir mencatat SJ sekali jalan di lokasi penerimaan: data nota (no_sj, nama_dealer, alamat_dealer, nominal, tanggal), penerima (nama_penerima, foto_penerima), lokasi (address, latitude, longitude), foto_nota_sj, dan notes. Response `data` adalah TrackingOrderResource.',
    )]
    public function store(CreateTrackingOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $senderName = $validated['nama_pengirim']
            ?? $request->user()?->name
            ?? $request->user()?->username;

        $orderData = [
            'user_id' => $request->user()->id,
            'no_sj' => trim($validated['no_sj']),
            'nama_dealer' => trim($validated['nama_dealer']),
            'alamat_dealer' => $validated['alamat_dealer'] ?? null,
            'jumlah_value_nota' => $validated['jumlah_value_nota'] ?? 0,
            'tanggal_nota' => $validated['tanggal_nota'] ?? now()->toDateString(),
            'tanggal_pengiriman' => $validated['tanggal_pengiriman'] ?? now()->toDateString(),
            'nama_pengirim' => $senderName,
            'nama_penerima' => $validated['nama_penerima'] ?? null,
            'address' => $validated['address'] ?? ($validated['alamat_dealer'] ?? null),
            'latitude' => isset($validated['latitude']) && $validated['latitude'] !== null ? (float) $validated['latitude'] : null,
            'longitude' => isset($validated['longitude']) && $validated['longitude'] !== null ? (float) $validated['longitude'] : null,
            'notes' => $validated['notes'] ?? null,
        ];

        if ($request->hasFile('foto_nota_sj')) {
            $orderData['foto_nota_sj'] = $request->file('foto_nota_sj')->store('tracking-orders/nota', 'public');
        }

        if ($request->hasFile('foto_penerima')) {
            $orderData['foto_penerima'] = $request->file('foto_penerima')->store('tracking-orders/penerima', 'public');
        }

        $trackingOrder = TrackingOrder::create($orderData);

        return response()->json([
            'success' => true,
            'message' => "Surat Jalan {$trackingOrder->no_sj} berhasil dibuat.",
            'data' => new TrackingOrderResource($trackingOrder),
        ], 201);
    }

    #[Endpoint(
        title: 'Daftar surat jalan',
        description: 'Mengembalikan halaman TrackingOrderResource milik user yang login (super admin melihat semua). Filter: kurir/nama_pengirim, date/tanggal_pengiriman, search (no_sj, dealer, alamat).',
    )]
    #[QueryParameter('kurir', 'Filter nama pengirim / kurir.', type: 'string')]
    #[QueryParameter('date', 'Tanggal pengiriman Y-m-d.', type: 'string', format: 'date')]
    #[QueryParameter('search', 'Cari no_sj, nama_dealer, atau address.', type: 'string')]
    #[QueryParameter('per_page', 'Jumlah item per halaman, maksimum 100.', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TrackingOrder::query()->visibleTo($request->user());

        // Filter by courier / driver name
        if ($request->filled('kurir') || $request->filled('nama_pengirim')) {
            $kurir = $request->input('kurir', $request->input('nama_pengirim'));
            $query->where('nama_pengirim', 'LIKE', "%{$kurir}%");
        }

        // Filter by delivery date (Y-m-d)
        if ($request->filled('date') || $request->filled('tanggal_pengiriman')) {
            $date = $request->input('date', $request->input('tanggal_pengiriman'));
            $query->whereDate('tanggal_pengiriman', $date);
        }

        // Search by No. SJ, Dealer, or Address
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('no_sj', 'LIKE', "%{$search}%")
                    ->orWhere('nama_dealer', 'LIKE', "%{$search}%")
                    ->orWhere('address', 'LIKE', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 100);
        $orders = $query->latest('tanggal_pengiriman')->paginate($perPage);

        return TrackingOrderResource::collection($orders);
    }

    #[Endpoint(
        title: 'Detail surat jalan by ID',
        description: 'Mengembalikan satu TrackingOrderResource: identitas SJ, dealer, nominal, tanggal, foto URL, dan lokasi penerimaan (address, latitude, longitude).',
    )]
    public function show(Request $request, TrackingOrder $trackingOrder): TrackingOrderResource
    {
        abort_unless($trackingOrder->isVisibleTo($request->user()), 404);

        return new TrackingOrderResource($trackingOrder);
    }

    #[Endpoint(
        title: 'Cari surat jalan by nomor SJ',
        description: 'Lookup barcode/QR di SJ milik user yang login. Response `data` TrackingOrderResource, atau 404 jika no_sj tidak ada.',
    )]
    public function findByNoSj(Request $request, string $noSj): JsonResponse
    {
        $order = TrackingOrder::query()
            ->visibleTo($request->user())
            ->where('no_sj', trim($noSj))
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => "Surat Jalan {$noSj} tidak ditemukan di sistem.",
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data Surat Jalan ditemukan.',
            'data' => new TrackingOrderResource($order),
        ]);
    }

    #[Endpoint(
        title: 'Daftar nama kurir',
        description: 'Mengembalikan array string `data` berisi nama_pengirim unik untuk selector di aplikasi.',
    )]
    public function drivers(Request $request): JsonResponse
    {
        $drivers = TrackingOrder::query()
            ->visibleTo($request->user())
            ->whereNotNull('nama_pengirim')
            ->where('nama_pengirim', '!=', '')
            ->distinct()
            ->orderBy('nama_pengirim')
            ->pluck('nama_pengirim');

        return response()->json([
            'success' => true,
            'data' => $drivers,
        ]);
    }

    #[Endpoint(
        title: 'Ringkasan pengiriman kurir',
        description: 'Angka `data`: total_assigned, total_delivered, total_pending, delivery_rate_percentage. Terkirim = SJ yang sudah punya foto atau nama penerima. Hanya SJ milik user yang login (super admin melihat semua). Default: hari ini.',
    )]
    #[QueryParameter('kurir', 'Filter tambahan nama pengirim.', type: 'string')]
    #[QueryParameter('date', 'Tanggal Y-m-d, atau all untuk semua tanggal.', type: 'string')]
    public function courierSummary(Request $request): JsonResponse
    {
        $query = TrackingOrder::query()->visibleTo($request->user());

        if ($request->filled('kurir') || $request->filled('nama_pengirim')) {
            $kurir = $request->input('kurir', $request->input('nama_pengirim'));
            $query->where('nama_pengirim', 'LIKE', "%{$kurir}%");
        }

        if ($request->filled('date')) {
            if ($request->input('date') !== 'all') {
                $query->whereDate('tanggal_pengiriman', $request->input('date'));
            }
        } elseif (! $request->filled('kurir') && ! $request->filled('nama_pengirim')) {
            // Tanpa query dari Profile: hitung SJ milik kurir hari ini
            $today = now()->toDateString();
            $query->where(function ($q) use ($today): void {
                $q->whereDate('tanggal_pengiriman', $today)
                    ->orWhere(function ($sub) use ($today): void {
                        $sub->whereNull('tanggal_pengiriman')
                            ->whereDate('created_at', $today);
                    });
            });
        }

        $totalAssigned = (clone $query)->count();

        // SJ dianggap terkirim begitu ada foto atau nama penerima
        $totalDelivered = (clone $query)->where(function ($q): void {
            $q->where(function ($sub): void {
                $sub->whereNotNull('foto_penerima')->where('foto_penerima', '!=', '');
            })->orWhere(function ($sub): void {
                $sub->whereNotNull('nama_penerima')->where('nama_penerima', '!=', '');
            });
        })->count();

        $totalPending = max(0, $totalAssigned - $totalDelivered);

        $deliveryRate = $totalAssigned > 0 ? round(($totalDelivered / $totalAssigned) * 100, 1) : 0;

        return response()->json([
            'success' => true,
            'message' => 'Ringkasan kurir berhasil dimuat.',
            'data' => [
                'total_assigned' => $totalAssigned,
                'total_delivered' => $totalDelivered,
                'total_pending' => $totalPending,
                'delivery_rate_percentage' => $deliveryRate,
            ],
        ]);
    }
}

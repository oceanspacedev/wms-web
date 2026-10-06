<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateTrackingOrderRequest;
use App\Http\Requests\Api\V1\SubmitPodRequest;
use App\Http\Resources\Api\V1\TrackingOrderResource;
use App\Models\TrackingOrder;
use App\Services\PodLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrackingOrderApiController extends Controller
{
    public function __construct(
        protected PodLocationService $locationService,
    ) {}

    /**
     * Create a new tracking order (Surat Jalan) from mobile app or API.
     */
    public function store(CreateTrackingOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $senderName = $validated['nama_pengirim']
            ?? $request->user()?->nama_lengkap
            ?? $request->user()?->name
            ?? $request->user()?->username;

        $orderData = [
            'no_sj' => trim($validated['no_sj']),
            'nama_dealer' => trim($validated['nama_dealer']),
            'alamat_dealer' => $validated['alamat_dealer'] ?? null,
            'jumlah_value_nota' => $validated['jumlah_value_nota'] ?? 0,
            'tanggal_nota' => $validated['tanggal_nota'] ?? now()->toDateString(),
            'tanggal_pengiriman' => $validated['tanggal_pengiriman'] ?? now()->toDateString(),
            'nama_pengirim' => $senderName,
            'nama_penerima' => $validated['nama_penerima'] ?? null,
            'address' => $validated['address'] ?? ($validated['alamat_dealer'] ?? null),
            'status' => $validated['status'] ?? 'IN_TRANSIT',
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

    /**
     * Display a listing of tracking orders with search & filtering for couriers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TrackingOrder::query();

        // Filter by courier / driver name
        if ($request->filled('kurir') || $request->filled('nama_pengirim')) {
            $kurir = $request->input('kurir', $request->input('nama_pengirim'));
            $query->where('nama_pengirim', 'LIKE', "%{$kurir}%");
        }

        // Filter by delivery status
        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->input('status')));
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

    /**
     * Display detail of a single tracking order by ID.
     */
    public function show(TrackingOrder $trackingOrder): TrackingOrderResource
    {
        return new TrackingOrderResource($trackingOrder);
    }

    /**
     * Look up a tracking order by Surat Jalan (SJ) number.
     * Ideal for mobile barcode / QR scanner.
     */
    public function findByNoSj(string $noSj): JsonResponse
    {
        $order = TrackingOrder::where('no_sj', trim($noSj))->first();

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

    /**
     * Submit Proof of Delivery (POD) from mobile camera & GPS.
     */
    public function submitPod(SubmitPodRequest $request, TrackingOrder $trackingOrder): JsonResponse
    {
        return $this->processPodSubmission($request, $trackingOrder);
    }

    /**
     * Submit Proof of Delivery (POD) directly by Surat Jalan number.
     */
    public function submitPodBySj(SubmitPodRequest $request, string $noSj): JsonResponse
    {
        $trackingOrder = TrackingOrder::where('no_sj', trim($noSj))->first();

        if (! $trackingOrder) {
            return response()->json([
                'success' => false,
                'message' => "Surat Jalan {$noSj} tidak ditemukan di sistem.",
                'data' => null,
            ], 404);
        }

        return $this->processPodSubmission($request, $trackingOrder);
    }

    /**
     * Shared logic to process uploaded proof photos, location, and mark as DELIVERED.
     */
    protected function processPodSubmission(SubmitPodRequest $request, TrackingOrder $trackingOrder): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $validated, $trackingOrder): void {
            // 1. Resolve GPS Coordinates (from request payload or photo EXIF metadata)
            $latitude = ! empty($validated['latitude']) ? (float) $validated['latitude'] : null;
            $longitude = ! empty($validated['longitude']) ? (float) $validated['longitude'] : null;

            if ($latitude === null && $request->hasFile('foto_penerima')) {
                $exifCoords = $this->locationService->extractExifGps($request->file('foto_penerima')->getRealPath());
                if ($exifCoords) {
                    $latitude = $exifCoords['latitude'];
                    $longitude = $exifCoords['longitude'];
                }
            }

            if ($latitude === null && $request->hasFile('foto_nota_sj')) {
                $exifCoords = $this->locationService->extractExifGps($request->file('foto_nota_sj')->getRealPath());
                if ($exifCoords) {
                    $latitude = $exifCoords['latitude'];
                    $longitude = $exifCoords['longitude'];
                }
            }

            // 2. Resolve Human-Readable Street Address (Reverse Geocoding or fallback)
            $address = $validated['address'] ?? null;
            if (empty($address) && $latitude !== null && $longitude !== null) {
                $address = $this->locationService->reverseGeocode($latitude, $longitude);
            }

            if (empty($address)) {
                $address = $trackingOrder->address ?: $trackingOrder->alamat_dealer;
            }

            // Append GPS coordinates tag to address if GPS is present and not already in address text
            if ($latitude !== null && $longitude !== null) {
                $coordsTag = "GPS: [{$latitude}, {$longitude}]";
                if (! str_contains((string) $address, 'GPS: [')) {
                    $address = $address ? "{$address} ({$coordsTag})" : $coordsTag;
                }
            }

            // 3. Prepare watermark info for photo overlay (GPS Map Camera style)
            $watermarkInfo = [
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'no_sj' => $trackingOrder->no_sj,
                'courier' => $validated['nama_pengirim'] ?? $trackingOrder->nama_pengirim,
            ];

            // 4. Process and stamp Foto Nota Surat Jalan
            if ($request->hasFile('foto_nota_sj')) {
                $file = $request->file('foto_nota_sj');
                $filename = 'nota_'.Str::slug($trackingOrder->no_sj).'_'.time().'_'.Str::random(6).'.jpg';
                $relativeDir = 'tracking-orders/nota';
                $fullDir = Storage::disk('public')->path($relativeDir);
                if (! file_exists($fullDir)) {
                    @mkdir($fullDir, 0755, true);
                }
                $destPath = $fullDir.DIRECTORY_SEPARATOR.$filename;
                $this->locationService->stampWatermarkOnPhoto($file->getRealPath(), $destPath, $watermarkInfo);
                $trackingOrder->foto_nota_sj = "{$relativeDir}/{$filename}";
            }

            // 5. Process and stamp Foto Penerima
            if ($request->hasFile('foto_penerima')) {
                $file = $request->file('foto_penerima');
                $filename = 'penerima_'.Str::slug($trackingOrder->no_sj).'_'.time().'_'.Str::random(6).'.jpg';
                $relativeDir = 'tracking-orders/penerima';
                $fullDir = Storage::disk('public')->path($relativeDir);
                if (! file_exists($fullDir)) {
                    @mkdir($fullDir, 0755, true);
                }
                $destPath = $fullDir.DIRECTORY_SEPARATOR.$filename;
                $this->locationService->stampWatermarkOnPhoto($file->getRealPath(), $destPath, $watermarkInfo);
                $trackingOrder->foto_penerima = "{$relativeDir}/{$filename}";
            }

            // 6. Update tracking order record with the captured address & DELIVERED status
            $trackingOrder->nama_penerima = $validated['nama_penerima'];
            $trackingOrder->address = $address;
            $trackingOrder->status = 'DELIVERED';

            if (! empty($validated['nama_pengirim'])) {
                $trackingOrder->nama_pengirim = $validated['nama_pengirim'];
            }

            if (! empty($validated['notes'])) {
                $trackingOrder->notes = $validated['notes'];
            }

            $trackingOrder->save();
        });

        return response()->json([
            'success' => true,
            'message' => "Bukti pengiriman (POD) untuk No. SJ {$trackingOrder->no_sj} berhasil disimpan.",
            'data' => new TrackingOrderResource($trackingOrder->fresh()),
        ], 200);
    }

    /**
     * Get distinct list of courier / driver names for mobile app selector.
     */
    public function drivers(): JsonResponse
    {
        $drivers = TrackingOrder::query()
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

    /**
     * Get courier daily delivery summary statistics.
     */
    public function courierSummary(Request $request): JsonResponse
    {
        $query = TrackingOrder::query();

        if ($request->filled('kurir') || $request->filled('nama_pengirim')) {
            $kurir = $request->input('kurir', $request->input('nama_pengirim'));
            $query->where('nama_pengirim', 'LIKE', "%{$kurir}%");
        } elseif ($user = $request->user()) {
            $identifiers = array_values(array_filter([$user->name, $user->username]));
            if (! empty($identifiers)) {
                $query->where(function ($q) use ($identifiers): void {
                    foreach ($identifiers as $identifier) {
                        $q->orWhere('nama_pengirim', 'LIKE', "%{$identifier}%");
                    }
                });
            }
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

        // Status SJ sudah dihapus di app, jadi hitung yang sudah ada nama/foto penerima atau status DELIVERED
        $totalDelivered = (clone $query)->where(function ($q): void {
            $q->where(function ($sub): void {
                $sub->whereNotNull('foto_penerima')->where('foto_penerima', '!=', '');
            })->orWhere(function ($sub): void {
                $sub->whereNotNull('nama_penerima')->where('nama_penerima', '!=', '');
            })->orWhere('status', 'DELIVERED');
        })->count();

        // total_pending = yang belum ada POD
        $totalPending = max(0, $totalAssigned - $totalDelivered);
        $totalInTransit = $totalPending;
        $totalReturned = (clone $query)->where('status', 'RETURNED')->count();

        $deliveryRate = $totalAssigned > 0 ? round(($totalDelivered / $totalAssigned) * 100, 1) : 0;

        return response()->json([
            'success' => true,
            'message' => 'Ringkasan kurir berhasil dimuat.',
            'data' => [
                'total_assigned' => $totalAssigned,
                'total_delivered' => $totalDelivered,
                'total_pending' => $totalPending,
                'total_in_transit' => $totalInTransit,
                'total_returned' => $totalReturned,
                'delivery_rate_percentage' => $deliveryRate,
            ],
        ]);
    }
}

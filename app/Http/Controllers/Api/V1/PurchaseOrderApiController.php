<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePurchaseOrderRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Purchase Order', 'Akses data penerimaan barang gudang.', 4)]
class PurchaseOrderApiController extends Controller
{
    /**
     * @var array<string, string>
     */
    public const PHOTO_DIRECTORIES = [
        'foto_do' => 'purchase-orders/do',
        'foto_resi' => 'purchase-orders/resi',
        'foto_penerimaan' => 'purchase-orders/penerimaan',
    ];

    #[Endpoint(
        title: 'Catat penerimaan purchase order',
        description: 'Gudang mencatat PO sekali jalan saat barang tiba: data DO (no_do, nama_supplier, tanggal_do, qty, keterangan; no_po opsional karena gudang umumnya tidak tahu nomor PO dan diisi purchasing saat pencocokan), resi ekspedisi bila ada (nama_kurir_ekspedisi, no_resi, qty_koli), status_penerimaan (Lengkap, Kurang, Rusak; selain Lengkap wajib catatan_gudang), lokasi (address, latitude, longitude), serta foto_do, foto_resi (opsional), dan foto_penerimaan. Response `data` adalah PurchaseOrderResource.',
    )]
    public function store(CreatePurchaseOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $receiverName = $validated['penerima_gudang']
            ?? $request->user()?->name
            ?? $request->user()?->username;

        $poData = [
            'user_id' => $request->user()->id,
            'no_po' => filled($validated['no_po'] ?? null) ? trim($validated['no_po']) : null,
            'no_do' => trim($validated['no_do']),
            'tanggal_do' => $validated['tanggal_do'] ?? null,
            'tanggal_datang' => $validated['tanggal_datang'] ?? now()->toDateString(),
            'nama_supplier' => trim($validated['nama_supplier']),
            'nama_gudang' => $validated['nama_gudang'] ?? 'GUDANG UTAMA',
            'alamat_gudang' => $validated['alamat_gudang'] ?? null,
            'nama_kurir_ekspedisi' => $validated['nama_kurir_ekspedisi'] ?? null,
            'no_resi' => $validated['no_resi'] ?? null,
            'penerima_gudang' => $receiverName,
            'qty_koli' => $validated['qty_koli'] ?? 1,
            'qty_unit' => $validated['qty_unit'] ?? 0,
            'keterangan_barang' => $validated['keterangan_barang'] ?? null,
            'status_penerimaan' => $validated['status_penerimaan'],
            'catatan_gudang' => $validated['catatan_gudang'] ?? null,
            'address' => $validated['address'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'status_verifikasi_finance' => 'Menunggu Pemeriksaan',
        ];

        foreach (self::PHOTO_DIRECTORIES as $field => $directory) {
            if ($request->hasFile($field)) {
                $poData[$field] = $request->file($field)->store($directory, 'public');
            }
        }

        $purchaseOrder = PurchaseOrder::create($poData);

        return response()->json([
            'success' => true,
            'message' => "Penerimaan barang DO {$purchaseOrder->no_do} berhasil dicatat.",
            'data' => new PurchaseOrderResource($purchaseOrder),
        ], 201);
    }

    #[Endpoint(
        title: 'Daftar purchase order',
        description: 'Mengembalikan halaman PurchaseOrderResource milik user yang login (super admin melihat semua). Filter search (no_po, no_do, no_resi, nama_supplier) dan status_penerimaan.',
    )]
    #[QueryParameter('search', 'Cari no_po, no_do, no_resi, atau nama_supplier.', type: 'string')]
    #[QueryParameter('status_penerimaan', 'Lengkap, Kurang, atau Rusak.', type: 'string')]
    #[QueryParameter('per_page', 'Jumlah item per halaman, maksimum 100.', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PurchaseOrder::query()->visibleTo($request->user());

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search): void {
                $builder->where('no_po', 'like', "%{$search}%")
                    ->orWhere('no_do', 'like', "%{$search}%")
                    ->orWhere('no_resi', 'like', "%{$search}%")
                    ->orWhere('nama_supplier', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status_penerimaan')) {
            $query->where('status_penerimaan', $request->string('status_penerimaan')->toString());
        }

        $perPage = min($request->integer('per_page', 20), 100);

        return PurchaseOrderResource::collection(
            $query->latest('tanggal_datang')->latest('id')->paginate($perPage)
        );
    }

    #[Endpoint(
        title: 'Detail penerimaan purchase order by ID',
        description: 'Mengembalikan satu PurchaseOrderResource. Dipakai aplikasi karena no_po bisa masih kosong.',
    )]
    public function show(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        abort_unless($purchaseOrder->isVisibleTo($request->user()), 404);

        return new PurchaseOrderResource($purchaseOrder);
    }

    #[Endpoint(
        title: 'Cari purchase order by nomor PO',
        description: 'Lookup `no_po`. Response `data` PurchaseOrderResource, atau 404 jika tidak ada.',
    )]
    public function findByNoPo(Request $request, string $noPo): JsonResponse
    {
        return $this->findOrder($request, 'no_po', $noPo, 'Purchase Order');
    }

    #[Endpoint(
        title: 'Cari purchase order by nomor DO',
        description: 'Lookup `no_do` (DO / surat jalan supplier). Response `data` PurchaseOrderResource, atau 404 jika tidak ada.',
    )]
    public function findByNoDo(Request $request, string $noDo): JsonResponse
    {
        return $this->findOrder($request, 'no_do', $noDo, 'DO');
    }

    private function findOrder(Request $request, string $column, string $value, string $label): JsonResponse
    {
        $order = PurchaseOrder::query()
            ->visibleTo($request->user())
            ->where($column, trim($value))
            ->first();

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => "{$label} {$value} tidak ditemukan.",
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => "{$label} ditemukan.",
            'data' => new PurchaseOrderResource($order),
        ]);
    }
}

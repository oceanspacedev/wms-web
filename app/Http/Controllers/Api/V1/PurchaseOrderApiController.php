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
    #[Endpoint(
        title: 'Buat purchase order',
        description: 'Menyimpan PO: no_po, supplier, gudang, qty, nominal, bukti serah terima. Response `data` adalah PurchaseOrderResource.',
    )]
    public function store(CreatePurchaseOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $receiverName = $validated['penerima_gudang']
            ?? $request->user()?->name
            ?? $request->user()?->username;

        $poData = [
            'no_po' => trim($validated['no_po']),
            'no_sj_supplier' => ! empty($validated['no_sj_supplier']) ? trim($validated['no_sj_supplier']) : null,
            'tanggal_po' => $validated['tanggal_po'] ?? now()->toDateString(),
            'tanggal_datang' => $validated['tanggal_datang'] ?? now()->toDateString(),
            'nama_supplier' => trim($validated['nama_supplier']),
            'nama_gudang' => $validated['nama_gudang'] ?? 'GUDANG UTAMA',
            'alamat_gudang' => $validated['alamat_gudang'] ?? null,
            'nama_kurir_ekspedisi' => $validated['nama_kurir_ekspedisi'] ?? null,
            'no_resi' => $validated['no_resi'] ?? null,
            'penerima_gudang' => $receiverName,
            'qty_koli' => $validated['qty_koli'] ?? 1,
            'qty_unit' => $validated['qty_unit'] ?? 0,
            'total_nominal' => $validated['total_nominal'] ?? 0,
            'keterangan_barang' => $validated['keterangan_barang'] ?? null,
            'status_penerimaan' => $validated['status_penerimaan'] ?? 'Lengkap',
            'catatan_gudang' => $validated['catatan_gudang'] ?? null,
            'status_verifikasi_finance' => 'Menunggu Pemeriksaan',
        ];

        if ($request->hasFile('bukti_serah_terima')) {
            $poData['bukti_serah_terima'] = $request->file('bukti_serah_terima')->store('purchase-orders/bukti', 'public');
        }

        $purchaseOrder = PurchaseOrder::create($poData);

        return response()->json([
            'success' => true,
            'message' => "Purchase Order {$purchaseOrder->no_po} berhasil dibuat.",
            'data' => new PurchaseOrderResource($purchaseOrder),
        ], 201);
    }

    #[Endpoint(
        title: 'Daftar purchase order',
        description: 'Mengembalikan halaman PurchaseOrderResource. Filter search (no_po, SJ supplier, nama_supplier) dan status_penerimaan.',
    )]
    #[QueryParameter('search', 'Cari no_po, no_sj_supplier, atau nama_supplier.', type: 'string')]
    #[QueryParameter('status_penerimaan', 'Lengkap, Kurang, Rusak, atau Belum Datang.', type: 'string')]
    #[QueryParameter('per_page', 'Jumlah item per halaman, maksimum 100.', type: 'integer')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PurchaseOrder::query();

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search): void {
                $builder->where('no_po', 'like', "%{$search}%")
                    ->orWhere('no_sj_supplier', 'like', "%{$search}%")
                    ->orWhere('nama_supplier', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status_penerimaan')) {
            $query->where('status_penerimaan', $request->string('status_penerimaan')->toString());
        }

        $perPage = min($request->integer('per_page', 20), 100);

        return PurchaseOrderResource::collection(
            $query->latest('tanggal_po')->paginate($perPage)
        );
    }

    #[Endpoint(
        title: 'Cari purchase order by nomor PO',
        description: 'Lookup `no_po`. Response `data` PurchaseOrderResource, atau 404 jika tidak ada.',
    )]
    public function findByNoPo(string $noPo): JsonResponse
    {
        return $this->findOrder('no_po', $noPo, 'Purchase Order');
    }

    #[Endpoint(
        title: 'Cari purchase order by SJ supplier',
        description: 'Lookup `no_sj_supplier`. Response `data` PurchaseOrderResource, atau 404 jika tidak ada.',
    )]
    public function findBySupplierSj(string $noSj): JsonResponse
    {
        return $this->findOrder('no_sj_supplier', $noSj, 'Surat Jalan supplier');
    }

    private function findOrder(string $column, string $value, string $label): JsonResponse
    {
        $order = PurchaseOrder::query()->where($column, trim($value))->first();

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

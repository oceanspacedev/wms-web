<?php

namespace App\Services;

use App\Models\StockAllocation;
use App\Models\StockAllocationLine;
use App\Models\StockAllocationTransfer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saran alokasi stok antar gudang dari data stok, penjualan & incoming per gudang × SKU (format tab "Data" file DOS).
 *
 * Per SKU dalam satu region: gudang yang stoknya di bawah target DOS menerima dari gudang yang stoknya di atas
 * target (gudang pengirim tetap menyisakan stok sebesar target DOS). Gudang paling kritis dilayani lebih dulu,
 * diambil dari gudang yang paling berlebih. Kekurangan yang tidak tertutup transfer menjadi rekomendasi PO.
 */
class StockAllocationService
{
    /**
     * Region yang tidak ikut dialokasikan: gudang retur dan gudang non-penjualan (aktiva, dll.).
     */
    public const EXCLUDED_REGIONS = ['RETUR', 'OTHER'];

    /**
     * Header kolom yang dikenali (nama kolom di file → field).
     *
     * @var array<string, list<string>>
     */
    private const HEADERS = [
        'warehouse' => ['GUDANG', 'KODE GUDANG', 'WAREHOUSE'],
        'region' => ['REGION', 'WILAYAH'],
        'brand' => ['BRAND'],
        'type' => ['TYPE', 'TIPE'],
        'color' => ['WARNA', 'COLOR'],
        'description' => ['KETERANGAN'],
        'qty' => ['QTY', 'JUMLAH'],
        'sku' => ['KODE SKU', 'SKU', 'KODE BARANG'],
        'name' => ['NAMA BARANG', 'NAMA PRODUK'],
    ];

    private const REQUIRED = ['warehouse', 'description', 'qty', 'sku'];

    public function __construct(private XlsxReader $reader) {}

    public function import(string $path, string $name, ?string $fileName, int $targetDos, int $minTransferQty, ?int $userId): StockAllocation
    {
        $rows = $this->read($path);

        return DB::transaction(function () use ($rows, $name, $fileName, $targetDos, $minTransferQty, $userId): StockAllocation {
            $allocation = StockAllocation::create([
                'name' => $name,
                'file_name' => $fileName,
                'target_dos' => $targetDos,
                'min_transfer_qty' => $minTransferQty,
                'created_by' => $userId,
            ]);

            $now = now();
            foreach (array_chunk($rows, 500) as $chunk) {
                StockAllocationLine::insert(array_map(fn (array $row): array => $row + [
                    'stock_allocation_id' => $allocation->id,
                    'status' => 'healthy',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $this->allocate($allocation);

            return $allocation->refresh();
        });
    }

    /**
     * Baca file stok: satu baris per gudang × SKU × keterangan (STOCK, ST 30 HARI, INCOMING).
     *
     * @return list<array{region: string, warehouse: string, sku: string, product_name: string, stock: int, incoming: int, sales_qty: int, sales_days: int}>
     */
    public function read(string $path): array
    {
        $strings = $this->reader->sharedStrings($path);
        $sheets = $this->reader->sheets($path);

        // File DOS menyimpan data mentahnya di tab "Data"; file lain cukup tab pertama yang header-nya cocok
        uksort($sheets, fn (string $a, string $b): int => (strcasecmp($b, 'Data') === 0) <=> (strcasecmp($a, 'Data') === 0));

        foreach ($sheets as $sheetPath) {
            $columns = null;
            $items = [];

            foreach ($this->reader->iterateRows($path, $sheetPath, $strings) as $row) {
                $rowNumber = (int) ($row['_row'] ?? 0);
                unset($row['_row']);

                if ($columns === null) {
                    $columns = $this->detectColumns($row);

                    if ($columns === null && $rowNumber >= 10) {
                        break;
                    }

                    continue;
                }

                $this->collect($items, $row, $columns);
            }

            if ($columns !== null) {
                return $this->finish($items);
            }
        }

        throw new RuntimeException('Kolom GUDANG, KETERANGAN, QTY dan KODE_SKU tidak ditemukan. Gunakan format tab "Data" di file DOS.');
    }

    /**
     * Hitung ulang status & saran transfer dari data stok yang tersimpan (saran lama, termasuk yang disetujui, diganti).
     */
    public function allocate(StockAllocation $allocation): void
    {
        DB::transaction(function () use ($allocation): void {
            $allocation->transfers()->delete();

            $target = $allocation->target_dos;
            $min = max(1, $allocation->min_transfer_qty);
            $lines = $allocation->lines()->get();

            foreach ($lines as $line) {
                $line->avg_daily_sales = round($this->daily($line), 2);
                $line->dos_before = $this->dos($line, $line->stock);
                $line->status = $this->status($line->dos_before, $target);
                $line->need_qty = max(0, (int) ceil(round($this->ideal($line, $target) - $line->stock - $line->incoming, 6)));
            }

            $transfers = [];
            $now = now();

            foreach ($lines->groupBy(fn (StockAllocationLine $line): string => $line->region.'|'.$line->sku) as $group) {
                $surplus = [];
                foreach ($group as $line) {
                    $qty = $this->surplus($line, $target);
                    if ($qty >= $min) {
                        $surplus[$line->id] = $qty;
                    }
                }

                if ($surplus === []) {
                    continue;
                }

                // Gudang paling kritis (DOS terkecil, lalu yang paling laku) menerima lebih dulu
                $receivers = $group->filter(fn (StockAllocationLine $line): bool => $line->need_qty >= $min)
                    ->sortBy([['dos_before', 'asc'], ['avg_daily_sales', 'desc']]);
                // Diambil dari gudang paling berlebih: yang tidak ada penjualan dulu, lalu DOS terbesar
                $sources = $group->filter(fn (StockAllocationLine $line): bool => isset($surplus[$line->id]))
                    ->sortByDesc(fn (StockAllocationLine $line): float => $line->dos_before ?? PHP_FLOAT_MAX);

                foreach ($receivers as $to) {
                    $remaining = $to->need_qty;

                    foreach ($sources as $from) {
                        $qty = min($remaining, $surplus[$from->id]);
                        if ($qty < $min) {
                            continue;
                        }

                        $transfers[] = [
                            'stock_allocation_id' => $allocation->id,
                            'from_line_id' => $from->id,
                            'to_line_id' => $to->id,
                            'region' => $to->region,
                            'sku' => $to->sku,
                            'product_name' => $to->product_name,
                            'from_warehouse' => $from->warehouse,
                            'to_warehouse' => $to->warehouse,
                            'suggested_qty' => $qty,
                            'qty' => $qty,
                            'status' => StockAllocationTransfer::STATUS_PENDING,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $surplus[$from->id] -= $qty;
                        $remaining -= $qty;

                        if ($remaining < $min) {
                            break;
                        }
                    }
                }
            }

            foreach (array_chunk($transfers, 500) as $chunk) {
                StockAllocationTransfer::insert($chunk);
            }

            $this->refreshTotals($allocation, $lines);
        });
    }

    /**
     * Hitung ulang stok & DOS sesudah transfer, sisa PO, dan ringkasan (saran yang ditolak tidak dihitung).
     *
     * @param  Collection<int, StockAllocationLine>|null  $lines
     */
    public function refreshTotals(StockAllocation $allocation, ?Collection $lines = null): void
    {
        $lines ??= $allocation->lines()->get();
        $active = $allocation->transfers()
            ->where('status', '!=', StockAllocationTransfer::STATUS_REJECTED)
            ->get(['from_line_id', 'to_line_id', 'qty']);
        $in = $active->groupBy('to_line_id')->map(fn (Collection $rows): int => (int) $rows->sum('qty'));
        $out = $active->groupBy('from_line_id')->map(fn (Collection $rows): int => (int) $rows->sum('qty'));

        $changed = [];
        foreach ($lines as $line) {
            $line->transfer_in = $in[$line->id] ?? 0;
            $line->transfer_out = $out[$line->id] ?? 0;
            $line->dos_after = $this->dos($line, $line->stockAfter());
            $line->po_qty = max(0, (int) ceil(round($this->ideal($line, $allocation->target_dos) - $line->stockAfter() - $line->incoming, 6)));

            if ($line->isDirty()) {
                $changed[] = $line;
            }
        }

        $this->saveLines($changed);

        $allocation->update([
            'warehouse_count' => $lines->pluck('warehouse')->unique()->count(),
            'sku_count' => $lines->pluck('sku')->unique()->count(),
            'transfer_count' => $active->count(),
            'transfer_qty' => (int) $active->sum('qty'),
            'po_qty_before' => (int) $lines->sum('need_qty'),
            'po_qty_after' => (int) $lines->sum('po_qty'),
        ]);
    }

    /**
     * @param  iterable<StockAllocationTransfer>  $transfers
     */
    public function decide(StockAllocation $allocation, iterable $transfers, string $status, ?int $userId): void
    {
        DB::transaction(function () use ($allocation, $transfers, $status, $userId): void {
            foreach ($transfers as $transfer) {
                $transfer->update([
                    'status' => $status,
                    'decided_by' => $status === StockAllocationTransfer::STATUS_PENDING ? null : $userId,
                    'decided_at' => $status === StockAllocationTransfer::STATUS_PENDING ? null : now(),
                ]);
            }

            $this->refreshTotals($allocation);
        });
    }

    public function changeQty(StockAllocationTransfer $transfer, int $qty, ?int $userId): void
    {
        DB::transaction(function () use ($transfer, $qty, $userId): void {
            $transfer->update([
                'qty' => $qty,
                'status' => StockAllocationTransfer::STATUS_APPROVED,
                'decided_by' => $userId,
                'decided_at' => now(),
            ]);

            $this->refreshTotals($transfer->allocation);
        });
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>|null
     */
    private function detectColumns(array $row): ?array
    {
        $columns = [];
        foreach ($row as $column => $value) {
            $header = trim((string) preg_replace('/[\s_]+/', ' ', strtoupper($value)));
            foreach (self::HEADERS as $field => $names) {
                if (! isset($columns[$field]) && in_array($header, $names, true)) {
                    $columns[$field] = $column;
                }
            }
        }

        foreach (self::REQUIRED as $field) {
            if (! isset($columns[$field])) {
                return null;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<string, string>  $row
     * @param  array<string, string>  $columns
     */
    private function collect(array &$items, array $row, array $columns): void
    {
        $value = fn (string $field): string => trim((string) preg_replace('/\s+/', ' ', $row[$columns[$field] ?? ''] ?? ''));

        $warehouse = $value('warehouse');
        $sku = $value('sku');
        if ($warehouse === '' || $sku === '') {
            return;
        }

        $region = strtoupper($value('region')) ?: 'SEMUA';
        $key = $region.'|'.$warehouse.'|'.$sku;
        $qty = (int) round((float) str_replace(',', '.', $value('qty')));

        $items[$key] ??= [
            'region' => $region,
            'warehouse' => $warehouse,
            'sku' => $sku,
            'product_name' => $value('name') ?: (trim($value('brand').' '.$value('type').' '.$value('color')) ?: $sku),
            'stock' => 0,
            'incoming' => 0,
            'sales' => [],
        ];

        $description = strtoupper($value('description'));

        if (str_contains($description, 'INCOMING') || str_contains($description, 'OTW')) {
            $items[$key]['incoming'] += $qty;
        } elseif (preg_match('/\b(?:ST|SALES|PENJUALAN)\b\D*?(\d+)\s*HARI/', $description, $match)) {
            $items[$key]['sales'][(int) $match[1]] = ($items[$key]['sales'][(int) $match[1]] ?? 0) + $qty;
        } elseif (str_contains($description, 'STOCK') || str_contains($description, 'STOK')) {
            $items[$key]['stock'] += $qty;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @return list<array{region: string, warehouse: string, sku: string, product_name: string, stock: int, incoming: int, sales_qty: int, sales_days: int}>
     */
    private function finish(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (in_array($item['region'], self::EXCLUDED_REGIONS, true)) {
                continue;
            }

            // Penjualan 30 hari dipakai kalau ada (sama dengan status DOS 30D di file), selain itu periode terpanjang
            $days = isset($item['sales'][30]) ? 30 : ($item['sales'] === [] ? 30 : max(array_keys($item['sales'])));
            $sales = max(0, (int) ($item['sales'][$days] ?? 0));
            $stock = max(0, $item['stock']);
            $incoming = max(0, $item['incoming']);

            if ($stock === 0 && $incoming === 0 && $sales === 0) {
                continue;
            }

            $rows[] = [
                'region' => $item['region'],
                'warehouse' => $item['warehouse'],
                'sku' => $item['sku'],
                'product_name' => mb_substr($item['product_name'], 0, 255),
                'stock' => $stock,
                'incoming' => $incoming,
                'sales_qty' => $sales,
                'sales_days' => max(1, $days),
            ];
        }

        return $rows;
    }

    private function daily(StockAllocationLine $line): float
    {
        return $line->sales_qty / max(1, $line->sales_days);
    }

    private function ideal(StockAllocationLine $line, int $target): float
    {
        return $target * $this->daily($line);
    }

    private function dos(StockAllocationLine $line, int $stock): ?float
    {
        $daily = $this->daily($line);

        return $daily > 0 ? round($stock / $daily, 1) : null;
    }

    /**
     * Stok yang boleh dipindah: kelebihan di atas target DOS (incoming ikut dihitung), maksimal stok yang ada.
     */
    private function surplus(StockAllocationLine $line, int $target): int
    {
        if ($line->stock <= 0) {
            return 0;
        }

        $gap = $line->stock + $line->incoming - $this->ideal($line, $target);

        return max(0, (int) floor(round(min($gap, $line->stock), 6)));
    }

    private function status(?float $dos, int $target): string
    {
        return match (true) {
            $dos === null => 'no_sales',
            $dos <= 7 => 'critical',
            $dos <= 14 => 'warning',
            $dos <= $target => 'healthy',
            default => 'overstock',
        };
    }

    /**
     * @param  list<StockAllocationLine>  $lines
     */
    private function saveLines(array $lines): void
    {
        $columns = ['id', 'stock_allocation_id', 'region', 'warehouse', 'sku', 'product_name', 'stock', 'incoming',
            'sales_qty', 'sales_days', 'avg_daily_sales', 'dos_before', 'dos_after', 'status', 'need_qty',
            'transfer_in', 'transfer_out', 'po_qty'];
        $now = now();

        foreach (array_chunk($lines, 500) as $chunk) {
            StockAllocationLine::upsert(
                array_map(fn (StockAllocationLine $line): array => $line->only($columns) + [
                    'created_at' => $line->created_at ?? $now,
                    'updated_at' => $now,
                ], $chunk),
                ['id'],
                array_values(array_diff([...$columns, 'updated_at'], ['id', 'stock_allocation_id'])),
            );

            foreach ($chunk as $line) {
                $line->syncOriginal();
            }
        }
    }
}

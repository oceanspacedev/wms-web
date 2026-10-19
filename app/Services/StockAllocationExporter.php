<?php

namespace App\Services;

use App\Models\StockAllocation;
use App\Models\StockAllocationLine;
use App\Models\StockAllocationTransfer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor hasil alokasi stok ke Excel: Saran Transfer, Per Gudang, dan Rekomendasi PO.
 */
class StockAllocationExporter
{
    public function download(StockAllocation $allocation): StreamedResponse
    {
        $fileName = 'Alokasi Stok '.$allocation->created_at?->format('Y-m-d His').'.xlsx';

        return response()->streamDownload(function () use ($allocation): void {
            $this->write($allocation, 'php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function write(StockAllocation $allocation, string $path): void
    {
        $header = (new Style)->setFontBold();

        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Saran Transfer');
        $writer->addRow(Row::fromValues([
            'REGION', 'KODE SKU', 'PRODUK', 'DARI GUDANG', 'DOS DARI (SEBELUM)', 'DOS DARI (SESUDAH)',
            'KE GUDANG', 'LAKU/HARI KE', 'DOS KE (SEBELUM)', 'DOS KE (SESUDAH)', 'QTY SARAN', 'QTY', 'STATUS',
        ], $header));

        $transfers = $allocation->transfers()->with(['fromLine', 'toLine'])
            ->orderBy('region')->orderBy('from_warehouse')->orderByDesc('qty')->get();
        foreach ($transfers as $transfer) {
            $writer->addRow(Row::fromValues([
                $transfer->region,
                $transfer->sku,
                $transfer->product_name,
                $transfer->from_warehouse,
                $transfer->fromLine?->dos_before,
                $transfer->fromLine?->dos_after,
                $transfer->to_warehouse,
                $transfer->toLine?->avg_daily_sales,
                $transfer->toLine?->dos_before,
                $transfer->toLine?->dos_after,
                $transfer->suggested_qty,
                $transfer->qty,
                StockAllocationTransfer::STATUS_LABELS[$transfer->status] ?? $transfer->status,
            ]));
        }

        $lines = $allocation->lines()->orderBy('region')->orderBy('warehouse')->orderBy('product_name')->get();

        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Per Gudang');
        $writer->addRow(Row::fromValues([
            'REGION', 'GUDANG', 'KODE SKU', 'PRODUK', 'STOK', 'INCOMING', 'PENJUALAN', 'HARI', 'LAKU/HARI',
            'DOS SEBELUM', 'STATUS', 'TRANSFER MASUK', 'TRANSFER KELUAR', 'STOK SESUDAH', 'DOS SESUDAH',
            'KEBUTUHAN PO AWAL', 'SISA PO',
        ], $header));

        foreach ($lines as $line) {
            $writer->addRow(Row::fromValues([
                $line->region,
                $line->warehouse,
                $line->sku,
                $line->product_name,
                $line->stock,
                $line->incoming,
                $line->sales_qty,
                $line->sales_days,
                $line->avg_daily_sales,
                $line->dos_before,
                $line->statusLabel(),
                $line->transfer_in,
                $line->transfer_out,
                $line->stockAfter(),
                $line->dos_after,
                $line->need_qty,
                $line->po_qty,
            ]));
        }

        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Rekomendasi PO');
        $writer->addRow(Row::fromValues([
            'REGION', 'GUDANG', 'KODE SKU', 'PRODUK', 'LAKU/HARI', 'STOK SESUDAH TRANSFER', 'INCOMING', 'DOS SESUDAH', 'QTY PO',
        ], $header));

        foreach ($lines->filter(fn (StockAllocationLine $line): bool => $line->po_qty > 0) as $line) {
            $writer->addRow(Row::fromValues([
                $line->region,
                $line->warehouse,
                $line->sku,
                $line->product_name,
                $line->avg_daily_sales,
                $line->stockAfter(),
                $line->incoming,
                $line->dos_after,
                $line->po_qty,
            ]));
        }

        $writer->close();
    }
}

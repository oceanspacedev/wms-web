<?php

namespace App\Services;

use App\Models\StockMutation;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lembar mutasi satu dokumen untuk gudang pengirim & penerima (Excel).
 */
class StockMutationExporter
{
    public function download(StockMutation $mutation): StreamedResponse
    {
        return response()->streamDownload(function () use ($mutation): void {
            $this->write($mutation, 'php://output');
        }, $mutation->number.' '.$mutation->from_warehouse.' ke '.$mutation->to_warehouse.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function write(StockMutation $mutation, string $path): void
    {
        $bold = (new Style)->setFontBold();

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Mutasi');

        foreach ([
            ['DOKUMEN MUTASI', $mutation->number],
            ['Dari gudang', $mutation->from_warehouse],
            ['Ke gudang', $mutation->to_warehouse],
            ['Region', $mutation->region],
            ['Status', $mutation->statusLabel()],
            ['Dibuat', $mutation->created_at?->format('d/m/Y H:i')],
            ['Dikirim', $mutation->shipped_at ? $mutation->shipped_at->format('d/m/Y H:i').($mutation->shipped_via ? ' · '.$mutation->shipped_via : '') : '-'],
            ['Diterima', $mutation->received_at?->format('d/m/Y H:i') ?? '-'],
        ] as $line) {
            $writer->addRow(Row::fromValuesWithStyles($line, null, [0 => $bold]));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['NO', 'KODE SKU', 'PRODUK', 'QTY KIRIM', 'QTY DITERIMA'], $bold));

        foreach ($mutation->items()->orderBy('product_name')->get()->values() as $index => $item) {
            $writer->addRow(Row::fromValues([$index + 1, $item->sku, $item->product_name, $item->qty, $item->received_qty]));
        }

        $writer->addRow(Row::fromValuesWithStyles(['', '', 'TOTAL', (int) $mutation->items()->sum('qty'), $mutation->status === StockMutation::STATUS_RECEIVED ? (int) $mutation->items()->sum('received_qty') : null], null, [2 => $bold, 3 => $bold, 4 => $bold]));

        $writer->close();
    }
}

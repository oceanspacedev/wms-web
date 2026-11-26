<?php

namespace App\Services;

use App\Models\StockMutation;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
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
        $border = new Border(
            new BorderPart(Border::TOP, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::RIGHT, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::LEFT, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
        );

        $baseLeft = $this->baseStyle(CellAlignment::LEFT);
        $options = new Options;
        $options->DEFAULT_ROW_STYLE = $baseLeft;

        $writer = new Writer($options);
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Mutasi');

        foreach ([1 => 8.0, 2 => 18.0, 3 => 44.0, 4 => 16.0, 5 => 16.0] as $columnIndex => $width) {
            $sheet->setColumnWidth($width, $columnIndex);
        }

        $metaLabel = $this->baseStyle(CellAlignment::LEFT, $border)
            ->setFontBold()
            ->setBackgroundColor('F2F2F2');
        $metaValue = $this->baseStyle(CellAlignment::LEFT, $border);

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
            $writer->addRow(Row::fromValuesWithStyles($line, $baseLeft, [0 => $metaLabel, 1 => $metaValue])->setHeight(20));
        }

        $writer->addRow(Row::fromValues([])->setHeight(12));

        $headerStyle = $this->baseStyle(CellAlignment::CENTER, $border)
            ->setFontBold()
            ->setBackgroundColor('D9E1F2');
        $writer->addRow(Row::fromValues(['NO', 'KODE SKU', 'PRODUK', 'QTY KIRIM', 'QTY DITERIMA'], $headerStyle)->setHeight(24));

        $rowStyles = [
            0 => $this->baseStyle(CellAlignment::CENTER, $border)->setFormat('#,##0'),
            1 => $this->baseStyle(CellAlignment::CENTER, $border),
            2 => $this->baseStyle(CellAlignment::LEFT, $border),
            3 => $this->baseStyle(CellAlignment::RIGHT, $border)->setFormat('#,##0'),
            4 => $this->baseStyle(CellAlignment::RIGHT, $border)->setFormat('#,##0'),
        ];

        foreach ($mutation->items()->orderBy('product_name')->get()->values() as $index => $item) {
            $writer->addRow(Row::fromValuesWithStyles([
                $index + 1,
                $item->sku,
                $item->product_name,
                $item->qty,
                $item->received_qty,
            ], $baseLeft, $rowStyles)->setHeight(20));
        }

        $totalLabelStyle = $this->baseStyle(CellAlignment::RIGHT, $border)
            ->setFontBold()
            ->setBackgroundColor('F2F2F2');
        $totalNumberStyle = $this->baseStyle(CellAlignment::RIGHT, $border)
            ->setFontBold()
            ->setBackgroundColor('F2F2F2')
            ->setFormat('#,##0');
        $totalEmptyStyle = $this->baseStyle(CellAlignment::CENTER, $border)
            ->setBackgroundColor('F2F2F2');

        $writer->addRow(Row::fromValuesWithStyles([
            '',
            '',
            'TOTAL',
            (int) $mutation->items()->sum('qty'),
            $mutation->status === StockMutation::STATUS_RECEIVED ? (int) $mutation->items()->sum('received_qty') : null,
        ], $baseLeft, [
            0 => $totalEmptyStyle,
            1 => $totalEmptyStyle,
            2 => $totalLabelStyle,
            3 => $totalNumberStyle,
            4 => $totalNumberStyle,
        ])->setHeight(22));

        $writer->close();
    }

    private function baseStyle(string $alignment, ?Border $border = null): Style
    {
        $style = (new Style)
            ->setFontName('Calibri')
            ->setFontSize(10)
            ->setFontColor(Color::BLACK)
            ->setCellAlignment($alignment)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setShouldWrapText(false);

        if ($border !== null) {
            $style->setBorder($border);
        }

        return $style;
    }
}

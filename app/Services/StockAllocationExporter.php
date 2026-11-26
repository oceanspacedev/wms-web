<?php

namespace App\Services;

use App\Models\StockAllocation;
use App\Models\StockAllocationLine;
use App\Models\StockAllocationTransfer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor hasil alokasi stok ke Excel: Saran Transfer, Per Gudang, dan Rekomendasi PO.
 */
class StockAllocationExporter
{
    /** @var array<string, string> */
    private const TRANSFER_STATUS_FILLS = [
        'Disetujui' => 'E2EFDA',
        'Ditolak' => 'FCE4D6',
        'Belum disetujui' => 'F2F2F2',
    ];

    /** @var array<string, string> */
    private const LINE_STATUS_FILLS = [
        'KRITIS' => 'FCE4D6',
        'KURANG TARGET' => 'FFF2CC',
        'AMAN' => 'E2EFDA',
        'BERLEBIH' => 'DDEBF7',
        'TIDAK ADA PENJUALAN' => 'F2F2F2',
    ];

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
        $border = $this->thinBorder();
        $options = new Options;
        $options->DEFAULT_ROW_STYLE = $this->baseCellStyle($border, CellAlignment::CENTER);

        $writer = new Writer($options);
        $writer->openToFile($path);

        $transfers = $allocation->transfers()
            ->with(['fromLine', 'toLine'])
            ->orderBy('region')
            ->orderBy('from_warehouse')
            ->orderByDesc('qty')
            ->get();

        $this->writeSheet(
            writer: $writer,
            isFirstSheet: true,
            title: 'Saran Transfer',
            headers: [
                'REGION', 'KODE SKU', 'PRODUK', 'DARI GUDANG', 'DOS DARI (SEBELUM)', 'DOS DARI (SESUDAH)',
                'KE GUDANG', 'LAKU/HARI KE', 'DOS KE (SEBELUM)', 'DOS KE (SESUDAH)', 'QTY SARAN', 'QTY', 'STATUS',
            ],
            columnWidths: [
                1 => 18.0,  // REGION
                2 => 16.0,  // KODE SKU
                3 => 44.0,  // PRODUK
                4 => 16.0,  // DARI GUDANG
                5 => 21.0,  // DOS DARI (SEBELUM)
                6 => 21.0,  // DOS DARI (SESUDAH)
                7 => 15.0,  // KE GUDANG
                8 => 16.0,  // LAKU/HARI KE
                9 => 20.0,  // DOS KE (SEBELUM)
                10 => 20.0, // DOS KE (SESUDAH)
                11 => 14.0, // QTY SARAN
                12 => 12.0, // QTY
                13 => 19.0, // STATUS
            ],
            columnStyles: $this->columnStyles($border, 13, [0, 2], [4, 5, 8, 9], [7], [10, 11]),
            rows: $transfers->map(fn (StockAllocationTransfer $transfer): array => [
                $this->cleanText($transfer->region),
                $this->cleanText($transfer->sku),
                $this->cleanText($transfer->product_name),
                $this->cleanText($transfer->from_warehouse),
                $transfer->fromLine?->dos_before,
                $transfer->fromLine?->dos_after,
                $this->cleanText($transfer->to_warehouse),
                $transfer->toLine?->avg_daily_sales,
                $transfer->toLine?->dos_before,
                $transfer->toLine?->dos_after,
                $transfer->suggested_qty,
                $transfer->qty,
                StockAllocationTransfer::STATUS_LABELS[$transfer->status] ?? $transfer->status,
            ]),
            statusColumnIndex: 12,
            statusFills: self::TRANSFER_STATUS_FILLS,
            border: $border,
        );

        $lines = $allocation->lines()
            ->orderBy('region')
            ->orderBy('warehouse')
            ->orderBy('product_name')
            ->get();

        $this->writeSheet(
            writer: $writer,
            isFirstSheet: false,
            title: 'Per Gudang',
            headers: [
                'REGION', 'GUDANG', 'KODE SKU', 'PRODUK', 'STOK', 'INCOMING', 'PENJUALAN', 'HARI', 'LAKU/HARI',
                'DOS SEBELUM', 'STATUS', 'TRANSFER MASUK', 'TRANSFER KELUAR', 'STOK SESUDAH', 'DOS SESUDAH',
                'KEBUTUHAN PO AWAL', 'SISA PO',
            ],
            columnWidths: [
                1 => 18.0,  // REGION
                2 => 15.0,  // GUDANG
                3 => 16.0,  // KODE SKU
                4 => 44.0,  // PRODUK
                5 => 12.0,  // STOK
                6 => 13.0,  // INCOMING
                7 => 14.0,  // PENJUALAN
                8 => 10.0,  // HARI
                9 => 14.0,  // LAKU/HARI
                10 => 16.0, // DOS SEBELUM
                11 => 23.0, // STATUS
                12 => 18.0, // TRANSFER MASUK
                13 => 19.0, // TRANSFER KELUAR
                14 => 16.0, // STOK SESUDAH
                15 => 16.0, // DOS SESUDAH
                16 => 22.0, // KEBUTUHAN PO AWAL
                17 => 13.0, // SISA PO
            ],
            columnStyles: $this->columnStyles($border, 17, [0, 3], [9, 14], [8], [4, 5, 6, 11, 12, 13, 15, 16], [7]),
            rows: $lines->map(fn (StockAllocationLine $line): array => [
                $this->cleanText($line->region),
                $this->cleanText($line->warehouse),
                $this->cleanText($line->sku),
                $this->cleanText($line->product_name),
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
            ]),
            statusColumnIndex: 10,
            statusFills: self::LINE_STATUS_FILLS,
            border: $border,
        );

        $this->writeSheet(
            writer: $writer,
            isFirstSheet: false,
            title: 'Rekomendasi PO',
            headers: [
                'REGION', 'GUDANG', 'KODE SKU', 'PRODUK', 'LAKU/HARI', 'STOK SESUDAH TRANSFER', 'INCOMING', 'DOS SESUDAH', 'QTY PO',
            ],
            columnWidths: [
                1 => 18.0, // REGION
                2 => 15.0, // GUDANG
                3 => 16.0, // KODE SKU
                4 => 44.0, // PRODUK
                5 => 14.0, // LAKU/HARI
                6 => 25.0, // STOK SESUDAH TRANSFER
                7 => 13.0, // INCOMING
                8 => 16.0, // DOS SESUDAH
                9 => 13.0, // QTY PO
            ],
            columnStyles: $this->columnStyles($border, 9, [0, 3], [7], [4], [5, 6, 8]),
            rows: $lines
                ->filter(fn (StockAllocationLine $line): bool => $line->po_qty > 0)
                ->map(fn (StockAllocationLine $line): array => [
                    $this->cleanText($line->region),
                    $this->cleanText($line->warehouse),
                    $this->cleanText($line->sku),
                    $this->cleanText($line->product_name),
                    $line->avg_daily_sales,
                    $line->stockAfter(),
                    $line->incoming,
                    $line->dos_after,
                    $line->po_qty,
                ]),
            statusColumnIndex: null,
            statusFills: [],
            border: $border,
        );

        $writer->close();
    }

    /**
     * Tulis satu worksheet beserta lebar kolom, freeze header, styling angka/status, dan AutoFilter.
     *
     * @param  list<string>  $headers
     * @param  array<positive-int, float>  $columnWidths
     * @param  array<int, Style>  $columnStyles
     * @param  iterable<int, list<mixed>>  $rows
     * @param  array<string, string>  $statusFills
     */
    private function writeSheet(
        Writer $writer,
        bool $isFirstSheet,
        string $title,
        array $headers,
        array $columnWidths,
        array $columnStyles,
        iterable $rows,
        ?int $statusColumnIndex,
        array $statusFills,
        Border $border,
    ): void {
        $sheet = $isFirstSheet ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($title);
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));

        foreach ($columnWidths as $columnIndex => $width) {
            $sheet->setColumnWidth($width, $columnIndex);
        }

        $headerStyle = $this->baseCellStyle($border, CellAlignment::CENTER)
            ->setFontBold()
            ->setBackgroundColor('D9E1F2');
        $rowStyle = $this->baseCellStyle($border, CellAlignment::CENTER);

        $statusStyles = [];
        foreach ($statusFills as $label => $hexColor) {
            $statusStyles[$label] = $this->baseCellStyle($border, CellAlignment::CENTER)
                ->setFontBold()
                ->setBackgroundColor($hexColor);
        }

        $writer->addRow(Row::fromValues($headers, $headerStyle)->setHeight(24));

        foreach ($rows as $rowValues) {
            $styles = $columnStyles;
            if ($statusColumnIndex !== null) {
                $statusLabel = (string) ($rowValues[$statusColumnIndex] ?? '');
                if (isset($statusStyles[$statusLabel])) {
                    $styles[$statusColumnIndex] = $statusStyles[$statusLabel];
                }
            }

            $writer->addRow(Row::fromValuesWithStyles($rowValues, $rowStyle, $styles)->setHeight(20));
        }

        $sheet->setAutoFilter(
            new AutoFilter(0, 1, count($headers) - 1, max(1, $sheet->getWrittenRowCount()))
        );
    }

    /**
     * Susun style per kolom agar teks, angka bulat, desimal DOS, dan laku/hari tampil rapi.
     *
     * @param  list<int>  $leftColumns
     * @param  list<int>  $dosColumns
     * @param  list<int>  $dailyColumns
     * @param  list<int>  $intRightColumns
     * @param  list<int>  $intCenterColumns
     * @return array<int, Style>
     */
    private function columnStyles(
        Border $border,
        int $columnCount,
        array $leftColumns,
        array $dosColumns,
        array $dailyColumns,
        array $intRightColumns,
        array $intCenterColumns = [],
    ): array {
        $centerStyle = $this->baseCellStyle($border, CellAlignment::CENTER);
        $leftStyle = $this->baseCellStyle($border, CellAlignment::LEFT);
        $dosStyle = $this->baseCellStyle($border, CellAlignment::RIGHT)->setFormat('#,##0.0');
        $dailyStyle = $this->baseCellStyle($border, CellAlignment::RIGHT)->setFormat('#,##0.00');
        $intRightStyle = $this->baseCellStyle($border, CellAlignment::RIGHT)->setFormat('#,##0');
        $intCenterStyle = $this->baseCellStyle($border, CellAlignment::CENTER)->setFormat('#,##0');

        $styles = array_fill(0, $columnCount, $centerStyle);

        foreach ($leftColumns as $col) {
            $styles[$col] = $leftStyle;
        }
        foreach ($dosColumns as $col) {
            $styles[$col] = $dosStyle;
        }
        foreach ($dailyColumns as $col) {
            $styles[$col] = $dailyStyle;
        }
        foreach ($intRightColumns as $col) {
            $styles[$col] = $intRightStyle;
        }
        foreach ($intCenterColumns as $col) {
            $styles[$col] = $intCenterStyle;
        }

        return $styles;
    }

    private function thinBorder(): Border
    {
        return new Border(
            new BorderPart(Border::TOP, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::RIGHT, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::LEFT, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
        );
    }

    private function baseCellStyle(Border $border, string $horizontalAlignment): Style
    {
        return (new Style)
            ->setFontName('Calibri')
            ->setFontSize(10)
            ->setFontColor(Color::BLACK)
            ->setCellAlignment($horizontalAlignment)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setShouldWrapText(false)
            ->setBorder($border);
    }

    private function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $cleaned !== '' ? $cleaned : null;
    }
}

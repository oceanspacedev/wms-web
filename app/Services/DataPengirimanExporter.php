<?php

namespace App\Services;

use App\Filament\Resources\CsaShipments\Pages\ListCsaShipments;
use App\Models\CsaShipment;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 * Ekspor Data Pengiriman ke Excel dengan susunan kolom yang sama seperti spreadsheet "Update Pengiriman".
 */
class DataPengirimanExporter
{
    /**
     * @var list<string>
     */
    public const HEADERS = [
        'TANGGAL ORDER', 'TANGGAL KIRIM', 'BADAN USAHA', 'DEPO [WAREHOUSE]', 'TUJUAN/DEALER', 'ALAMAT KIRIM',
        'NAMA KOTA', 'BRAND', 'NOMOR SJ', 'TOTAL NOMINAL SJ', 'REFFNOTE', 'QTY UNIT', 'QTY KOLI', 'BERAT',
        'KETENTUAN BIAYA KIRIM', 'NAMA EKSPEDISI', 'NO RESI AWB', 'BIAYA KIRIM', 'STATUS PEMBAYARAN',
        'STATUS PENGIRIMAN', 'TANGGAL DITERIMA', 'LEAD TIME PROSES', 'LEAD TIME KIRIM', 'LEAD TIME KESELURUHAN',
        'KET. ISI UNIT',
    ];

    /**
     * Lebar kolom (index 1-based sesuai OpenSpout: 1 = A .. 25 = Y).
     *
     * @var array<positive-int, float>
     */
    private const COLUMN_WIDTHS = [
        1 => 17.0,  // TANGGAL ORDER
        2 => 17.0,  // TANGGAL KIRIM
        3 => 35.0,  // BADAN USAHA
        4 => 21.0,  // DEPO [WAREHOUSE]
        5 => 30.0,  // TUJUAN/DEALER
        6 => 48.0,  // ALAMAT KIRIM
        7 => 22.0,  // NAMA KOTA
        8 => 16.0,  // BRAND
        9 => 20.0,  // NOMOR SJ
        10 => 21.0, // TOTAL NOMINAL SJ
        11 => 22.0, // REFFNOTE
        12 => 13.0, // QTY UNIT
        13 => 13.0, // QTY KOLI
        14 => 13.0, // BERAT
        15 => 25.0, // KETENTUAN BIAYA KIRIM
        16 => 20.0, // NAMA EKSPEDISI
        17 => 22.0, // NO RESI AWB
        18 => 18.0, // BIAYA KIRIM
        19 => 22.0, // STATUS PEMBAYARAN
        20 => 22.0, // STATUS PENGIRIMAN
        21 => 20.0, // TANGGAL DITERIMA
        22 => 20.0, // LEAD TIME PROSES
        23 => 19.0, // LEAD TIME KIRIM
        24 => 25.0, // LEAD TIME KESELURUHAN
        25 => 42.0, // KET. ISI UNIT
    ];

    /** Kolom tanggal (index 0-based). */
    private const DATE_COLUMNS = [0, 1, 20];

    /** Kolom nominal rupiah (index 0-based). */
    private const MONEY_COLUMNS = [9, 17];

    /** Kolom angka bulat qty (index 0-based). */
    private const QTY_COLUMNS = [11, 12];

    /** Kolom angka bulat lead time rata tengah (index 0-based). */
    private const LEAD_TIME_COLUMNS = [21, 22, 23];

    /** Kolom teks panjang rata kiri (index 0-based). */
    private const LEFT_TEXT_COLUMNS = [2, 4, 5, 24];

    /**
     * @param  Builder<CsaShipment>  $query
     */
    public function download(Builder $query, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $this->write($query, 'php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  Builder<CsaShipment>  $query
     */
    public function write(Builder $query, string $path): void
    {
        $thinBorder = new Border(
            new BorderPart(Border::TOP, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::RIGHT, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
            new BorderPart(Border::LEFT, Color::BLACK, Border::WIDTH_THIN, Border::STYLE_SOLID),
        );

        $options = new Options;
        $options->DEFAULT_ROW_STYLE = $this->baseCellStyle($thinBorder, CellAlignment::CENTER);

        foreach (self::COLUMN_WIDTHS as $columnIndex => $width) {
            $options->setColumnWidth($width, $columnIndex);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('DATA PENGIRIMAN');
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));

        foreach (self::COLUMN_WIDTHS as $columnIndex => $width) {
            $sheet->setColumnWidth($width, $columnIndex);
        }

        $headerStyle = $this->baseCellStyle($thinBorder, CellAlignment::CENTER)
            ->setFontBold()
            ->setBackgroundColor('D9E1F2');

        $writer->addRow(Row::fromValues(self::HEADERS, $headerStyle)->setHeight(24));

        $rowStyle = $this->baseCellStyle($thinBorder, CellAlignment::CENTER);
        $columnStyles = $this->buildColumnStyles($thinBorder);

        foreach ($query->lazy(1000) as $shipment) {
            $writer->addRow(
                Row::fromValuesWithStyles($this->row($shipment), $rowStyle, $columnStyles)->setHeight(20)
            );
        }

        $sheet->setAutoFilter(
            new AutoFilter(0, 1, count(self::HEADERS) - 1, max(1, $sheet->getWrittenRowCount()))
        );

        $writer->close();
    }

    /**
     * @return list<mixed>
     */
    public function row(CsaShipment $shipment): array
    {
        $rawBadanUsaha = $this->cleanText($shipment->badan_usaha);
        $badanUsaha = $rawBadanUsaha !== null
            ? (ListCsaShipments::canonicalBadanUsaha($rawBadanUsaha) ?: $rawBadanUsaha)
            : null;

        return [
            $this->date($shipment->tanggal_order),
            $this->date($shipment->tanggal_kirim),
            $badanUsaha,
            $this->cleanText($shipment->target_sheet),
            $this->cleanText($shipment->tujuan_dealer),
            $this->cleanText($shipment->alamat_kirim),
            $this->cleanText($shipment->nama_kota),
            $this->cleanText($shipment->brand),
            $this->cleanText($shipment->no_sj),
            $shipment->total_nominal_sj !== null ? (float) $shipment->total_nominal_sj : null,
            $this->cleanText($shipment->reff_note),
            $shipment->qty_unit,
            $shipment->qty_koli,
            $shipment->berat !== null ? (float) $shipment->berat : null,
            $this->cleanText($shipment->ketentuan_biaya_kirim),
            $this->cleanText($shipment->nama_ekspedisi),
            $this->cleanText($shipment->no_resi_awb),
            $shipment->biaya_kirim !== null ? (float) $shipment->biaya_kirim : null,
            $this->cleanText($shipment->status_pembayaran),
            $this->cleanText($shipment->status_pengiriman),
            $this->date($shipment->tanggal_diterima),
            $shipment->lead_time_proses,
            $shipment->lead_time_kirim,
            $shipment->lead_time_keseluruhan,
            $this->cleanText($shipment->ket_isi_unit),
        ];
    }

    /**
     * @return array<int, Style>
     */
    private function buildColumnStyles(Border $border): array
    {
        $centerTextStyle = $this->baseCellStyle($border, CellAlignment::CENTER);
        $leftTextStyle = $this->baseCellStyle($border, CellAlignment::LEFT);
        $dateStyle = $this->baseCellStyle($border, CellAlignment::CENTER)->setFormat('dd/mm/yyyy');
        $moneyStyle = $this->baseCellStyle($border, CellAlignment::RIGHT)->setFormat('#,##0');
        $qtyStyle = $this->baseCellStyle($border, CellAlignment::RIGHT)->setFormat('#,##0');
        $weightStyle = $this->baseCellStyle($border, CellAlignment::RIGHT)->setFormat('#,##0.##');
        $leadTimeStyle = $this->baseCellStyle($border, CellAlignment::CENTER)->setFormat('#,##0');

        $styles = array_fill(0, count(self::HEADERS), $centerTextStyle);

        foreach (self::LEFT_TEXT_COLUMNS as $col) {
            $styles[$col] = $leftTextStyle;
        }
        foreach (self::DATE_COLUMNS as $col) {
            $styles[$col] = $dateStyle;
        }
        foreach (self::MONEY_COLUMNS as $col) {
            $styles[$col] = $moneyStyle;
        }
        foreach (self::QTY_COLUMNS as $col) {
            $styles[$col] = $qtyStyle;
        }
        foreach (self::LEAD_TIME_COLUMNS as $col) {
            $styles[$col] = $leadTimeStyle;
        }

        $styles[13] = $weightStyle;

        return $styles;
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

    private function date(?CarbonInterface $date): ?DateTimeImmutable
    {
        return $date ? DateTimeImmutable::createFromInterface($date->copy()->startOfDay()) : null;
    }
}

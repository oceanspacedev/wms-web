<?php

namespace App\Services;

use App\Models\CsaShipment;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
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

    /** Kolom tanggal (index 0-based). */
    private const DATE_COLUMNS = [0, 1, 20];

    /** Kolom nominal rupiah (index 0-based). */
    private const MONEY_COLUMNS = [9, 17];

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
        $writer = new Writer;
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues(self::HEADERS, (new Style)->setFontBold()->setBackgroundColor('B7E1CD')));

        $dateStyle = (new Style)->setFormat('dd/mm/yyyy');
        $moneyStyle = (new Style)->setFormat('#,##0');
        $columnStyles = array_fill_keys(self::DATE_COLUMNS, $dateStyle) + array_fill_keys(self::MONEY_COLUMNS, $moneyStyle);

        foreach ($query->lazy(1000) as $shipment) {
            $writer->addRow(Row::fromValuesWithStyles($this->row($shipment), null, $columnStyles));
        }

        $writer->close();
    }

    /**
     * @return list<mixed>
     */
    public function row(CsaShipment $shipment): array
    {
        return [
            $this->date($shipment->tanggal_order),
            $this->date($shipment->tanggal_kirim),
            $shipment->badan_usaha,
            $shipment->target_sheet,
            $shipment->tujuan_dealer,
            $shipment->alamat_kirim,
            $shipment->nama_kota,
            $shipment->brand,
            $shipment->no_sj,
            $shipment->total_nominal_sj !== null ? (float) $shipment->total_nominal_sj : null,
            $shipment->reff_note,
            $shipment->qty_unit,
            $shipment->qty_koli,
            $shipment->berat !== null ? (float) $shipment->berat : null,
            $shipment->ketentuan_biaya_kirim,
            $shipment->nama_ekspedisi,
            $shipment->no_resi_awb,
            $shipment->biaya_kirim !== null ? (float) $shipment->biaya_kirim : null,
            $shipment->status_pembayaran,
            $shipment->status_pengiriman,
            $this->date($shipment->tanggal_diterima),
            $shipment->lead_time_proses,
            $shipment->lead_time_kirim,
            $shipment->lead_time_keseluruhan,
            $shipment->ket_isi_unit,
        ];
    }

    private function date(?CarbonInterface $date): ?DateTimeImmutable
    {
        return $date ? DateTimeImmutable::createFromInterface($date->copy()->startOfDay()) : null;
    }
}

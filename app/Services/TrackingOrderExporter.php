<?php

namespace App\Services;

use App\Filament\Resources\TrackingOrders\TrackingOrderResource;
use App\Models\TrackingOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Ekspor Surat Jalan yang dicatat kurir ke Excel, dengan foto nota & foto penerima tertempel di sel
 * (diperkecil supaya file tidak terlalu besar). Link detail SJ tetap ada untuk melihat foto ukuran penuh.
 */
class TrackingOrderExporter
{
    /**
     * @var list<string>
     */
    public const HEADERS = [
        'TANGGAL PENGIRIMAN', 'NOMOR SJ', 'NAMA DEALER', 'ALAMAT DEALER', 'JUMLAH VALUE NOTA', 'TANGGAL NOTA',
        'NAMA PENGIRIM', 'NAMA PENERIMA', 'FOTO NOTA', 'FOTO PENERIMA', 'LOKASI PENERIMAAN', 'LATITUDE', 'LONGITUDE',
        'CATATAN', 'DICATAT', 'DETAIL',
    ];

    /** Kolom foto: [huruf kolom => [atribut, folder lama]]. */
    private const PHOTO_COLUMNS = [
        'I' => ['foto_nota_sj', 'tracking-orders/nota'],
        'J' => ['foto_penerima', 'tracking-orders/penerima'],
    ];

    /** Sisi terpanjang foto di Excel (piksel). */
    private const THUMBNAIL_SIZE = 160;

    /** @var list<string> */
    private array $temporaryFiles = [];

    /**
     * @param  iterable<TrackingOrder>  $orders
     */
    public function write(iterable $orders, string $path): int
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Surat Jalan');
        $this->writeHeader($sheet);

        $row = 1;

        try {
            foreach ($orders as $order) {
                $this->writeRow($sheet, ++$row, $order);
            }

            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
            array_map(fn (string $file): bool => @unlink($file), $this->temporaryFiles);
            $this->temporaryFiles = [];
        }

        return $row - 1;
    }

    private function writeHeader(Worksheet $sheet): void
    {
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->getStyle('A1:P1')->getFont()->setBold(true);
        $sheet->getStyle('A1:P1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('B7E1CD');
        $sheet->freezePane('C2');

        foreach (['A' => 14, 'B' => 16, 'C' => 26, 'D' => 34, 'E' => 16, 'F' => 13, 'G' => 18, 'H' => 18, 'I' => 24, 'J' => 24, 'K' => 34, 'L' => 11, 'M' => 11, 'N' => 24, 'O' => 16, 'P' => 12] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function writeRow(Worksheet $sheet, int $row, TrackingOrder $order): void
    {
        $sheet->fromArray([
            $this->date($order->tanggal_pengiriman),
            $order->no_sj,
            $order->nama_dealer,
            $order->alamat_dealer,
            $order->jumlah_value_nota !== null ? (float) $order->jumlah_value_nota : null,
            $this->date($order->tanggal_nota),
            $order->nama_pengirim,
            $order->nama_penerima,
            null,
            null,
            $order->address,
            $order->latitude,
            $order->longitude,
            $order->notes,
            $order->created_at !== null ? Date::PHPToExcel($order->created_at) : null,
            'Buka',
        ], null, 'A'.$row, true);

        // Nomor SJ & lat/long tetap teks/angka apa adanya, bukan dibulatkan Excel
        $sheet->setCellValueExplicit('B'.$row, (string) $order->no_sj, DataType::TYPE_STRING);
        $sheet->getStyle("A{$row}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle("O{$row}")->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
        $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("A{$row}:P{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);

        $sheet->getCell("P{$row}")->getHyperlink()->setUrl(TrackingOrderResource::getUrl('view', ['record' => $order]));
        $sheet->getStyle("P{$row}")->getFont()->setUnderline(true)->getColor()->setRGB('0563C1');

        $hasPhoto = false;

        foreach (self::PHOTO_COLUMNS as $column => [$attribute, $directory]) {
            [$thumbnail, $problem] = $this->thumbnail($order->{$attribute}, $directory);

            if ($thumbnail === null) {
                $sheet->setCellValue($column.$row, $problem);

                continue;
            }

            $drawing = new Drawing;
            $drawing->setName($attribute);
            $drawing->setPath($thumbnail);
            $drawing->setHeight(self::THUMBNAIL_SIZE * 3 / 4);
            $drawing->setCoordinates($column.$row);
            $drawing->setOffsetX(4);
            $drawing->setOffsetY(4);
            $drawing->setWorksheet($sheet);
            $hasPhoto = true;
        }

        // Tinggi baris mengikuti foto (poin; 120 px ≈ 90 pt)
        if ($hasPhoto) {
            $sheet->getRowDimension($row)->setRowHeight(self::THUMBNAIL_SIZE * 3 / 4 * 0.75 + 8);
        }
    }

    /**
     * Foto diperkecil ke file JPEG sementara, atau keterangan kenapa foto tidak bisa ditampilkan.
     *
     * @return array{0: ?string, 1: string} [file thumbnail, keterangan pengganti]
     */
    private function thumbnail(?string $path, string $directory): array
    {
        if (blank($path)) {
            return [null, '-'];
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return [null, 'Lihat di link DETAIL'];
        }

        $path = Str::startsWith($path, 'tracking-orders/') ? $path : "{$directory}/{$path}";

        try {
            $contents = Storage::disk(config('filesystems.default'))->get($path);
        } catch (Throwable) {
            $contents = null;
        }

        if ($contents === null) {
            return [null, 'Foto tidak ditemukan'];
        }

        // Format yang tidak dikenal GD (mis. HEIC dari iPhone)
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return [null, 'Format foto tidak didukung'];
        }

        $scale = self::THUMBNAIL_SIZE / max(imagesx($image), imagesy($image));
        $thumbnail = $scale < 1 ? imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale))) : $image;
        $file = tempnam(sys_get_temp_dir(), 'sj-foto').'.jpg';

        imagejpeg($thumbnail, $file, 80);
        $this->temporaryFiles[] = $file;

        return [$file, ''];
    }

    private function date(?CarbonInterface $date): ?float
    {
        return $date !== null ? Date::PHPToExcel($date->copy()->startOfDay()) : null;
    }
}

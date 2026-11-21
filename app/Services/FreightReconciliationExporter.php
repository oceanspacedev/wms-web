<?php

namespace App\Services;

use App\Models\FreightInvoice;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FreightReconciliationExporter
{
    /**
     * Download hasil rekonsiliasi lengkap dalam format Excel rapi (.xlsx).
     */
    public function downloadFull(FreightInvoice $invoice): StreamedResponse
    {
        $filename = "Hasil_Rekonsiliasi_{$invoice->invoice_number}.xlsx";

        return response()->streamDownload(function () use ($invoice): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Hasil Rekonsiliasi');

            $headers = [
                'No',
                'Nomor Resi / AWB',
                'Nomor SJ',
                'Badan Usaha',
                'Reffnote',
                'Brand',
                'Depo Asal',
                'Kota Tujuan',
                'Layanan',
                'Nominal SJ (Rp)',
                'Rasio Ongkir (%)',
                'Berat Tagihan (kg)',
                'Berat Gudang (kg)',
                'Tarif Tagihan (Rp/kg)',
                'Tarif Kontrak (Rp/kg)',
                'Biaya Asuransi (Rp)',
                'Total Ditagih (Rp)',
                'Layak Dibayar (Rp)',
                'Koreksi / Selisih (Rp)',
                'Cek Tarif Kontrak',
                'Cek Data Pengiriman',
                'Kunci Cocok',
                'Catatan Audit',
            ];

            $sheet->fromArray($headers, null, 'A1');
            $sheet->freezePane('D2');
            $sheet->setShowGridlines(true);

            $row = 1;
            $items = $invoice->items()->orderBy('id')->get();

            foreach ($items as $index => $item) {
                $row++;
                $no = $index + 1;

                $sheet->setCellValue("A{$row}", $no);
                $sheet->setCellValueExplicit("B{$row}", (string) $item->no_resi_awb, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("C{$row}", (string) ($item->no_sj ?? '-'), DataType::TYPE_STRING);
                $sheet->setCellValue("D{$row}", $item->shipment_badan_usaha ?? '-');
                $sheet->setCellValueExplicit("E{$row}", (string) ($item->shipment_reff_note ?? '-'), DataType::TYPE_STRING);
                $sheet->setCellValue("F{$row}", $item->shipment_brand ?? '-');
                $sheet->setCellValue("G{$row}", $item->origin_depo ?? '-');
                $sheet->setCellValue("H{$row}", $item->destination_city ?? '-');
                $sheet->setCellValue("I{$row}", $item->service_type ?? '-');

                // Angka finansial & berat
                $sheet->setCellValue("J{$row}", (float) ($item->shipment_nominal_sj ?? 0));
                $sheet->setCellValue("K{$row}", $item->costRatioPercent() !== null ? round($item->costRatioPercent(), 2) / 100 : 0);
                $sheet->setCellValue("L{$row}", (float) $item->billed_weight);
                $sheet->setCellValue("M{$row}", $item->shipment_weight !== null ? (float) $item->shipment_weight : '');
                $sheet->setCellValue("N{$row}", (float) $item->billed_rate);
                $sheet->setCellValue("O{$row}", (float) ($item->agreed_rate ?? 0));
                $sheet->setCellValue("P{$row}", (float) ($item->billed_insurance ?? 0));
                $sheet->setCellValue("Q{$row}", (float) $item->billed_total);

                if ($item->isUnverified()) {
                    $sheet->setCellValue("R{$row}", 'Belum terverifikasi');
                } else {
                    $sheet->setCellValue("R{$row}", (float) $item->approvedAmount());
                }

                $sheet->setCellValue("S{$row}", (float) $item->correctionAmount());
                $sheet->setCellValue("T{$row}", $item->checkLabel());
                $sheet->setCellValue("U{$row}", $item->shipmentMatchLabel());
                $sheet->setCellValue("V{$row}", $item->shipment_matched_by ?? '-');

                $auditNote = implode(' | ', array_filter([
                    $item->isManuallyApproved() ? 'Disetujui manual: '.$item->manual_note : null,
                    $item->audit_notes,
                ]));
                $sheet->setCellValue("W{$row}", $auditNote);
            }

            $lastCol = 'W';
            $dataEndRow = $row;

            // Baris Total di paling bawah jika ada data
            if ($dataEndRow > 1) {
                $totalRow = $dataEndRow + 1;
                $sheet->setCellValue("A{$totalRow}", 'TOTAL');
                $sheet->mergeCells("A{$totalRow}:I{$totalRow}");

                $sheet->setCellValue("J{$totalRow}", "=SUM(J2:J{$dataEndRow})");
                $sheet->setCellValue("Q{$totalRow}", "=SUM(Q2:Q{$dataEndRow})");
                $sheet->setCellValue("R{$totalRow}", "=SUM(R2:R{$dataEndRow})");
                $sheet->setCellValue("S{$totalRow}", "=SUM(S2:S{$dataEndRow})");

                // Style total row
                $totalRange = "A{$totalRow}:{$lastCol}{$totalRow}";
                $sheet->getStyle($totalRange)->getFont()->setBold(true);
                $sheet->getStyle($totalRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
                $sheet->getStyle($totalRange)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle($totalRange)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
                $sheet->getStyle("A{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Format numbers pada total
                $sheet->getStyle("J{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle("Q{$totalRow}:S{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');

                $applyEndRow = $totalRow;
            } else {
                $applyEndRow = 1;
            }

            // Styling header
            $headerRange = "A1:{$lastCol}1";
            $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A8A');
            $sheet->getStyle($headerRange)->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setWrapText(true);
            $sheet->getRowDimension(1)->setRowHeight(30);

            // Number formatting untuk data rows
            if ($dataEndRow > 1) {
                // Nominal SJ
                $sheet->getStyle("J2:J{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0');
                // Rasio Ongkir (%)
                $sheet->getStyle("K2:K{$dataEndRow}")->getNumberFormat()->setFormatCode('0.00%');
                // Berat
                $sheet->getStyle("L2:M{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0.0');
                // Tarif & Rupiah
                $sheet->getStyle("N2:S{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0');

                // Alignments
                $sheet->getStyle("A2:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("B2:C{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("F2:G{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("I2:I{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("T2:V{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Wrap text pada Catatan Audit agar teks panjang tidak meluber/bablas ke kolom kosong sebelah kanan
                $sheet->getStyle("W2:W{$dataEndRow}")->getAlignment()
                    ->setWrapText(true)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                // Borders untuk semua cell data
                $tableRange = "A1:{$lastCol}{$applyEndRow}";
                $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

                // Zebra striping
                for ($r = 2; $r <= $dataEndRow; $r++) {
                    if ($r % 2 === 0) {
                        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
                    }
                }
            }

            // Auto filter
            $sheet->setAutoFilter("A1:{$lastCol}1");

            // Auto column widths dengan minimum lebar yang nyaman
            $minWidths = [
                'A' => 6,   // No
                'B' => 18,  // Nomor Resi
                'C' => 18,  // Nomor SJ
                'D' => 24,  // Badan Usaha
                'E' => 16,  // Reffnote
                'F' => 14,  // Brand
                'G' => 16,  // Depo Asal
                'H' => 20,  // Kota Tujuan
                'I' => 14,  // Layanan
                'J' => 18,  // Nominal SJ
                'K' => 15,  // Rasio Ongkir
                'L' => 16,  // Berat Tagihan
                'M' => 16,  // Berat Gudang
                'N' => 18,  // Tarif Tagihan
                'O' => 18,  // Tarif Kontrak
                'P' => 18,  // Biaya Asuransi
                'Q' => 18,  // Total Ditagih
                'R' => 18,  // Layak Dibayar
                'S' => 20,  // Koreksi / Selisih
                'T' => 18,  // Cek Tarif Kontrak
                'U' => 22,  // Cek Data Pengiriman
                'V' => 16,  // Kunci Cocok
                'W' => 45,  // Catatan Audit (lebar nyaman dan ter-wrap)
            ];

            foreach ($minWidths as $col => $minWidth) {
                $sheet->getColumnDimension($col)->setWidth($minWidth);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Download Berita Acara Selisih dalam format Excel rapi (.xlsx).
     */
    public function downloadDispute(FreightInvoice $invoice): StreamedResponse
    {
        $filename = "Berita_Acara_Koreksi_Tagihan_{$invoice->invoice_number}.xlsx";

        return response()->streamDownload(function () use ($invoice): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Berita Acara Selisih');

            $headers = [
                'No',
                'Nomor Resi / AWB',
                'Nomor SJ',
                'Badan Usaha',
                'Reffnote',
                'Brand',
                'Depo Asal',
                'Kota Tujuan',
                'Layanan',
                'Berat Tagihan (kg)',
                'Berat Timbang (kg)',
                'Berat Gudang (kg)',
                'Selisih Berat (kg)',
                'Tarif Tagihan (Rp/kg)',
                'Tarif Kontrak PKS (Rp/kg)',
                'Total Tagihan Ekspedisi (Rp)',
                'Total Sesuai Kontrak (Rp)',
                'Nilai Koreksi / Potongan (Rp)',
                'Status Temuan',
                'Status Data Pengiriman',
                'Keterangan Audit',
            ];

            $sheet->fromArray($headers, null, 'A1');
            $sheet->freezePane('D2');
            $sheet->setShowGridlines(true);

            $row = 1;
            $items = $invoice->items()->where('audit_status', '!=', 'matched')->orderBy('id')->get();

            foreach ($items as $index => $item) {
                $row++;
                $no = $index + 1;

                $sheet->setCellValue("A{$row}", $no);
                $sheet->setCellValueExplicit("B{$row}", (string) $item->no_resi_awb, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit("C{$row}", (string) ($item->no_sj ?? '-'), DataType::TYPE_STRING);
                $sheet->setCellValue("D{$row}", $item->shipment_badan_usaha ?? '-');
                $sheet->setCellValueExplicit("E{$row}", (string) ($item->shipment_reff_note ?? '-'), DataType::TYPE_STRING);
                $sheet->setCellValue("F{$row}", $item->shipment_brand ?? '-');
                $sheet->setCellValue("G{$row}", $item->origin_depo ?? '-');
                $sheet->setCellValue("H{$row}", $item->destination_city ?? '-');
                $sheet->setCellValue("I{$row}", $item->service_type ?? '-');

                $sheet->setCellValue("J{$row}", (float) $item->billed_weight);
                $sheet->setCellValue("K{$row}", (float) ($item->actual_weight ?? 0));
                $sheet->setCellValue("L{$row}", $item->shipment_weight !== null ? (float) $item->shipment_weight : '');
                $sheet->setCellValue("M{$row}", (float) ($item->weight_discrepancy ?? 0));

                $sheet->setCellValue("N{$row}", (float) $item->billed_rate);
                $sheet->setCellValue("O{$row}", (float) ($item->agreed_rate ?? 0));
                $sheet->setCellValue("P{$row}", (float) $item->billed_total);

                if ($item->isUnverified()) {
                    $sheet->setCellValue("Q{$row}", 'Belum terverifikasi');
                } else {
                    $sheet->setCellValue("Q{$row}", (float) $item->approvedAmount());
                }

                $sheet->setCellValue("R{$row}", (float) $item->correctionAmount());
                $sheet->setCellValue("S{$row}", $item->checkLabel());
                $sheet->setCellValue("T{$row}", $item->shipmentMatchLabel());

                $auditNote = implode(' | ', array_filter([
                    $item->isManuallyApproved() ? 'Disetujui manual: '.$item->manual_note : null,
                    $item->audit_notes,
                ]));
                $sheet->setCellValue("U{$row}", $auditNote);
            }

            $lastCol = 'U';
            $dataEndRow = $row;

            if ($dataEndRow > 1) {
                $totalRow = $dataEndRow + 1;
                $sheet->setCellValue("A{$totalRow}", 'TOTAL KOREKSI');
                $sheet->mergeCells("A{$totalRow}:O{$totalRow}");

                $sheet->setCellValue("P{$totalRow}", "=SUM(P2:P{$dataEndRow})");
                $sheet->setCellValue("Q{$totalRow}", "=SUM(Q2:Q{$dataEndRow})");
                $sheet->setCellValue("R{$totalRow}", "=SUM(R2:R{$dataEndRow})");

                $totalRange = "A{$totalRow}:{$lastCol}{$totalRow}";
                $sheet->getStyle($totalRange)->getFont()->setBold(true);
                $sheet->getStyle($totalRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEF2F2');
                $sheet->getStyle($totalRange)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle($totalRange)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
                $sheet->getStyle("A{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle("P{$totalRow}:R{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
                $applyEndRow = $totalRow;
            } else {
                $applyEndRow = 1;
            }

            // Header dispute: warna Merah Bata / Crimson elegan
            $headerRange = "A1:{$lastCol}1";
            $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('991B1B');
            $sheet->getStyle($headerRange)->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setWrapText(true);
            $sheet->getRowDimension(1)->setRowHeight(30);

            if ($dataEndRow > 1) {
                $sheet->getStyle("J2:M{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0.0');
                $sheet->getStyle("N2:R{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0');

                $sheet->getStyle("A2:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("B2:C{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("F2:G{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("I2:I{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("S2:T{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                // Wrap text pada Keterangan Audit agar teks panjang tidak meluber/bablas ke kolom kosong sebelah kanan
                $sheet->getStyle("U2:U{$dataEndRow}")->getAlignment()
                    ->setWrapText(true)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                $tableRange = "A1:{$lastCol}{$applyEndRow}";
                $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

                for ($r = 2; $r <= $dataEndRow; $r++) {
                    if ($r % 2 === 0) {
                        $sheet->getStyle("A{$r}:{$lastCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFBFB');
                    }
                }
            }

            $sheet->setAutoFilter("A1:{$lastCol}1");

            $minWidths = [
                'A' => 6,   // No
                'B' => 18,  // Nomor Resi
                'C' => 18,  // Nomor SJ
                'D' => 24,  // Badan Usaha
                'E' => 16,  // Reffnote
                'F' => 14,  // Brand
                'G' => 16,  // Depo Asal
                'H' => 20,  // Kota Tujuan
                'I' => 14,  // Layanan
                'J' => 16,  // Berat Tagihan
                'K' => 16,  // Berat Timbang
                'L' => 16,  // Berat Gudang
                'M' => 16,  // Selisih Berat
                'N' => 18,  // Tarif Tagihan
                'O' => 20,  // Tarif Kontrak
                'P' => 20,  // Total Tagihan Ekspedisi
                'Q' => 20,  // Total Sesuai Kontrak
                'R' => 22,  // Nilai Koreksi
                'S' => 20,  // Status Temuan
                'T' => 22,  // Status Pengiriman
                'U' => 45,  // Catatan Audit (lebar nyaman dan ter-wrap)
            ];

            foreach ($minWidths as $col => $minWidth) {
                $sheet->getColumnDimension($col)->setWidth($minWidth);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}

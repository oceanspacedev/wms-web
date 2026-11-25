<?php

namespace App\Services;

use App\Models\BrandDosTarget;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Import & export Excel Master Target DOS per brand (mendukung format tabel Monitoring DOS).
 */
class BrandDosTargetExcelService
{
    /**
     * Standar 16 baris Monitoring DOS (dipakai juga saat download template di database kosong).
     *
     * @var list<array{company: string, label: string, target_dos: ?int}>
     */
    public const DEFAULT_ROWS = [
        ['company' => 'PT. MSI', 'label' => 'TECNO ONLINE', 'target_dos' => 20],
        ['company' => 'PT. MSI', 'label' => 'TECNO OFFLINE', 'target_dos' => 35],
        ['company' => 'PT. MSI', 'label' => 'MOTOROLA', 'target_dos' => 45],
        ['company' => 'PT. MSI', 'label' => 'MOTOPAD', 'target_dos' => 60],
        ['company' => 'PT. MSI', 'label' => 'ITEL', 'target_dos' => 40],
        ['company' => 'PT. MSI', 'label' => 'VILLAON', 'target_dos' => 40],
        ['company' => 'PT. MSI', 'label' => 'IPHONE', 'target_dos' => 45],
        ['company' => 'PT. MSI', 'label' => 'INFINIX', 'target_dos' => null],
        ['company' => 'PT. MSI', 'label' => 'SAMSUNG', 'target_dos' => 30],
        ['company' => 'CV. TOP', 'label' => 'REALME WJ', 'target_dos' => 40],
        ['company' => 'CV. TOP', 'label' => 'REALME CJ', 'target_dos' => 40],
        ['company' => 'CV. TOP', 'label' => 'SAMSUNG', 'target_dos' => 30],
        ['company' => 'PT. SMI', 'label' => 'MINIMO', 'target_dos' => null],
        ['company' => 'PT. SMI', 'label' => 'RAPA', 'target_dos' => null],
        ['company' => 'PT. SMI', 'label' => 'ORAIMO', 'target_dos' => null],
        ['company' => 'PT. SMI', 'label' => 'ORAIMO ONLINE', 'target_dos' => null],
    ];

    public function __construct(private XlsxReader $reader) {}

    /**
     * Import file Excel (.xlsx) ke tabel brand_dos_targets.
     */
    public function import(string $path, bool $replaceExisting = false): int
    {
        $strings = $this->reader->sharedStrings($path);
        $sheets = $this->reader->sheets($path);

        foreach ($sheets as $sheetPath) {
            $columns = null;
            $parsed = [];
            $lastCompany = null;

            foreach ($this->reader->iterateRows($path, $sheetPath, $strings) as $row) {
                $rowNumber = (int) ($row['_row'] ?? 0);
                unset($row['_row']);

                if ($columns === null) {
                    $columns = $this->detectColumns($row);

                    if ($columns === null && $rowNumber >= 15) {
                        break;
                    }

                    continue;
                }

                $rawBrand = trim((string) preg_replace('/\s+/', ' ', (string) ($row[$columns['brand']] ?? '')));
                if ($rawBrand === '') {
                    continue;
                }

                $upperBrand = strtoupper($rawBrand);
                if (in_array($upperBrand, ['BRAND', 'NAMA BRAND', 'TOTAL', 'GRAND TOTAL', 'SUBTOTAL', 'RATA-RATA'], true)) {
                    continue;
                }

                $rawCompany = isset($columns['company'])
                    ? trim((string) preg_replace('/\s+/', ' ', (string) ($row[$columns['company']] ?? '')))
                    : '';

                if ($rawCompany !== '') {
                    $lastCompany = $this->normalizeCompany($rawCompany);
                }

                $rawTarget = isset($columns['target_dos'])
                    ? trim((string) ($row[$columns['target_dos']] ?? ''))
                    : '';

                $targetDos = null;
                if ($rawTarget !== '' && $rawTarget !== '-' && $rawTarget !== '–' && is_numeric(str_replace(',', '.', $rawTarget))) {
                    $val = (int) round((float) str_replace(',', '.', $rawTarget));
                    if ($val > 0) {
                        $targetDos = min(365, max(1, $val));
                    }
                }

                $key = ($lastCompany ?? '').'|'.$upperBrand;
                $parsed[$key] = [
                    'company' => $lastCompany,
                    'label' => $upperBrand,
                    'target_dos' => $targetDos,
                ];
            }

            if ($columns !== null) {
                if ($parsed === []) {
                    throw new RuntimeException('Tidak ada baris brand yang ditemukan di file Excel.');
                }

                return DB::transaction(function () use ($parsed, $replaceExisting): int {
                    if ($replaceExisting) {
                        BrandDosTarget::query()->delete();
                    }

                    foreach ($parsed as $item) {
                        [$brand, $segment, $region] = BrandDosTarget::inferFromLabel($item['label']);

                        BrandDosTarget::updateOrCreate(
                            [
                                'company' => $item['company'],
                                'label' => $item['label'],
                            ],
                            [
                                'brand' => $brand,
                                'segment' => $segment,
                                'region_scope' => $region,
                                'target_dos' => $item['target_dos'],
                                'is_active' => true,
                            ],
                        );
                    }

                    return count($parsed);
                });
            }
        }

        throw new RuntimeException('Kolom BRAND dan TARGET DOS tidak ditemukan. Pastikan file Excel memiliki kolom COMPANY, BRAND, dan TARGET DOS.');
    }

    public function download(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $this->write('php://output');
        }, 'Master_Target_DOS.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function write(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10)->getColor()->setRGB('000000');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Target DOS');
        $sheet->setShowGridlines(true);

        $sheet->fromArray(['COMPANY', 'BRAND', 'TARGET DOS BY BRAND'], null, 'A1');

        $records = BrandDosTarget::query()->orderBy('id')->get(['company', 'label', 'target_dos']);
        $rows = $records->isNotEmpty()
            ? $records->map(fn (BrandDosTarget $r): array => [
                'company' => $r->company,
                'label' => $r->label,
                'target_dos' => $r->target_dos,
            ])->all()
            : self::DEFAULT_ROWS;

        $rowNum = 1;
        foreach ($rows as $row) {
            $rowNum++;
            $sheet->setCellValue("A{$rowNum}", $row['company'] ?? '');
            $sheet->setCellValue("B{$rowNum}", $row['label']);
            if ($row['target_dos'] !== null) {
                $sheet->setCellValue("C{$rowNum}", (int) $row['target_dos']);
            }
        }

        $tableRange = "A1:C{$rowNum}";
        $sheet->getStyle($tableRange)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('000000');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet->getStyle('A1:C1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);

        if ($rowNum > 1) {
            $sheet->getStyle("A2:A{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("B2:B{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("C2:C{$rowNum}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(22);

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param  array<string, string>  $row
     * @return array{company?: string, brand: string, target_dos: string}|null
     */
    private function detectColumns(array $row): ?array
    {
        $columns = [];

        foreach ($row as $col => $value) {
            $header = trim((string) preg_replace('/[\s_]+/', ' ', strtoupper((string) $value)));
            if ($header === '') {
                continue;
            }

            if (! isset($columns['company']) && in_array($header, ['COMPANY', 'PERUSAHAAN', 'BADAN USAHA', 'PT'], true)) {
                $columns['company'] = $col;
            } elseif (! isset($columns['brand']) && in_array($header, ['BRAND', 'NAMA BRAND', 'MERK'], true)) {
                $columns['brand'] = $col;
            } elseif (! isset($columns['target_dos']) && (str_contains($header, 'TARGET') || in_array($header, ['DOS', 'TARGET DOS'], true))) {
                $columns['target_dos'] = $col;
            }
        }

        if (! isset($columns['brand']) || ! isset($columns['target_dos'])) {
            return null;
        }

        return $columns;
    }

    private function normalizeCompany(string $company): string
    {
        $upper = strtoupper(trim($company));
        // Standarisasi "PT.MSI" -> "PT. MSI", "CV.TOP" -> "CV. TOP"
        $normalized = (string) preg_replace('/^(PT|CV)\.\s*/', '$1. ', $upper);

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }
}

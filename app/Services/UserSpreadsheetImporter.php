<?php

namespace App\Services;

use App\Jobs\SendUserAccountActivatedWhatsAppJob;
use App\Models\User;
use App\Support\WhatsAppNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Buat akun aplikasi WMS dari template Excel yang cukup berisi nama lengkap dan nomor WhatsApp.
 * Email dibiarkan kosong; username dibuat dari nama, dan password awal hanya dikirim ke WhatsApp.
 */
class UserSpreadsheetImporter
{
    /**
     * Kolom template; tanda * menandai kolom wajib.
     *
     * @var list<string>
     */
    public const HEADERS = ['Nama Lengkap*', 'Nomor WhatsApp*'];

    public function __construct(private ImportedAccountCreator $accounts) {}

    /**
     * Template kosong tanpa baris contoh, supaya tidak ada akun contoh yang ikut terbuat kalau lupa dihapus.
     */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $options = new Options;
            $options->setColumnWidth(35, 1, 2);

            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(self::HEADERS, (new Style)->setFontBold()));
            $writer->close();
        }, 'Template Import Pengguna.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Semua akun dibuat dalam satu transaksi: kalau gagal di tengah jalan, tidak ada akun yang tersimpan.
     * Nomor WhatsApp yang sudah dipakai akun lain dilewati, jadi file yang sama aman diimpor ulang.
     *
     * @return array{created: list<string>, existing: list<string>, incomplete: list<string>}
     */
    public function import(string $path, bool $sendWhatsApp = true): array
    {
        $rows = $this->rows($path);

        return DB::transaction(function () use ($rows, $sendWhatsApp): array {
            $result = ['created' => [], 'existing' => [], 'incomplete' => []];
            $messages = [];

            foreach ($rows as $rowNumber => [$name, $whatsApp]) {
                $whatsAppNumber = WhatsAppNumber::normalize($whatsApp);

                if ($name === '' || ! WhatsAppNumber::isValid($whatsAppNumber)) {
                    $result['incomplete'][] = $name !== '' ? $name : "Baris {$rowNumber}";

                    continue;
                }

                if (User::query()->where('whatsapp_number', $whatsAppNumber)->exists()) {
                    $result['existing'][] = $name;

                    continue;
                }

                $messages[] = $this->accounts->create($name, $whatsAppNumber, null, $name, 'user');

                $result['created'][] = $name;
            }

            if ($sendWhatsApp) {
                SendUserAccountActivatedWhatsAppJob::dispatchOneByOne($messages);
            }

            return $result;
        });
    }

    /**
     * Nama & nomor WhatsApp per baris sheet pertama, dengan nomor baris Excel sebagai key. Baris kosong dilewati.
     *
     * @return array<int, array{string, string}>
     */
    private function rows(string $path): array
    {
        $reader = new Reader;

        try {
            $reader->open($path);
        } catch (OpenSpoutException) {
            throw new RuntimeException('File bukan Excel (.xlsx) yang valid.');
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                return $this->sheetRows($sheet->getRowIterator());
            }
        } finally {
            $reader->close();
        }

        return [];
    }

    /**
     * @param  iterable<int, Row>  $sheetRows
     * @return array<int, array{string, string}>
     */
    private function sheetRows(iterable $sheetRows): array
    {
        $columns = null;
        $rows = [];

        foreach ($sheetRows as $rowNumber => $row) {
            $cells = array_map($this->cellText(...), $row->toArray());

            if ($columns === null) {
                // Header dicocokkan tanpa membedakan huruf besar/kecil dan tanda *
                $headers = array_map(fn (string $cell): string => Str::lower(Str::squish(str_replace('*', '', $cell))), $cells);
                $name = array_search('nama lengkap', $headers, true);
                $whatsApp = array_search('nomor whatsapp', $headers, true);

                if ($name === false || $whatsApp === false) {
                    throw new RuntimeException('Kolom "Nama Lengkap" dan "Nomor WhatsApp" tidak ditemukan di baris pertama. Gunakan template import pengguna.');
                }

                $columns = [$name, $whatsApp];

                continue;
            }

            $values = [Str::squish($cells[$columns[0]] ?? ''), $cells[$columns[1]] ?? ''];

            if ($values !== ['', '']) {
                $rows[$rowNumber] = $values;
            }
        }

        return $rows;
    }

    /**
     * Nomor yang diketik tanpa format teks dibaca Excel sebagai angka (mis. 81234567890 atau 6.28123E+12).
     */
    private function cellText(mixed $value): string
    {
        return match (true) {
            is_float($value) => number_format($value, 0, '', ''),
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }
}

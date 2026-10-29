<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor daftar pengguna ke Excel, mengikuti filter & pencarian tabel Users. Password tidak ikut diekspor.
 */
class UserExporter
{
    /**
     * @var list<string>
     */
    public const HEADERS = [
        'NAMA', 'USERNAME', 'EMAIL', 'WHATSAPP', 'ROLE', 'KODE GUDANG ARSIP', 'CABANG', 'TANGGAL DIBUAT',
    ];

    /** Kolom tanggal (index 0-based). */
    private const DATE_COLUMNS = [7];

    /**
     * @param  Builder<User>  $query
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
     * @param  Builder<User>  $query
     */
    public function write(Builder $query, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $writer->addRow(Row::fromValues(self::HEADERS, (new Style)->setFontBold()));

        $columnStyles = array_fill_keys(self::DATE_COLUMNS, (new Style)->setFormat('dd/mm/yyyy hh:mm'));

        foreach ($query->with('roles')->lazy(1000) as $user) {
            $writer->addRow(Row::fromValuesWithStyles($this->row($user), null, $columnStyles));
        }

        $writer->close();
    }

    /**
     * @return list<mixed>
     */
    public function row(User $user): array
    {
        return [
            $user->name,
            $user->username,
            $user->email,
            // Tetap teks supaya Excel tidak mengubah nomor jadi angka eksponensial
            $user->whatsapp_number,
            $user->roles->pluck('name')->implode(', '),
            $user->kode_gudang_arsip,
            $user->cabang,
            $this->dateTime($user->created_at),
        ];
    }

    private function dateTime(?CarbonInterface $date): ?DateTimeImmutable
    {
        return $date ? DateTimeImmutable::createFromInterface($date) : null;
    }
}

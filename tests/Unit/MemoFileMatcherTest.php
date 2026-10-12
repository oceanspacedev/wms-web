<?php

namespace Tests\Unit;

use App\Support\MemoFileMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MemoFileMatcherTest extends TestCase
{
    /**
     * Mengikuti format data contoh SCM Arsip: "PO/2026/0103 / SJ-0103".
     *
     * @var array<int, array{id: string, po_sj_number: ?string}>
     */
    private const PROGRAMS = [
        ['id' => '1', 'po_sj_number' => 'PO/2026/0101 / SJ-0101'],
        ['id' => '3', 'po_sj_number' => 'PO/2026/0103 / SJ-0103'],
        ['id' => '4', 'po_sj_number' => 'PO/2026/0104 / SJ-0104'],
        ['id' => '5', 'po_sj_number' => 'PO/2026/0105 / SJ-0105'],
        ['id' => '10', 'po_sj_number' => 'PO/2026/0111 / SJ-0111'],
        ['id' => '11', 'po_sj_number' => 'PO/2026/0111 / SJ-0111'],
        ['id' => '12', 'po_sj_number' => null],
    ];

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function fileNames(): array
    {
        return [
            'nomor PO lengkap' => ['PO-2026-0103.pdf', '3'],
            'nomor PO dengan garis bawah' => ['po_2026_0105 memo.PDF', '5'],
            'nomor SJ' => ['SJ-0104.pdf', '4'],
            'nomor urut saja' => ['DO 0105 scan.jpg', '5'],
            'nomor urut tanpa nol di depan' => ['memo 101.png', '1'],
            'nama foto bawaan HP' => ['IMG_20261011_0832.jpg', null],
            'nomor dipakai dua PO' => ['SJ-0111.pdf', null],
            'nomor lebih panjang dari PO' => ['SJ-01034.pdf', null],
        ];
    }

    #[DataProvider('fileNames')]
    public function test_guesses_program_from_file_name(string $fileName, ?string $expectedProgramId): void
    {
        $this->assertSame($expectedProgramId, MemoFileMatcher::guess($fileName, self::PROGRAMS));
    }
}

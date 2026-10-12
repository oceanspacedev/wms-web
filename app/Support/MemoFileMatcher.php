<?php

namespace App\Support;

/**
 * Menebak PO/SJ di SCM Arsip dari nama file Memo/DO,
 * misal "PO-2026-0103.pdf", "SJ-0104.pdf" atau "DO 0105 scan.jpg".
 */
class MemoFileMatcher
{
    /**
     * @param  array<int, array{id: string|int, po_sj_number?: ?string}>  $programs
     * @return string|null ID program jika tepat satu PO yang cocok; null jika tidak ada atau ragu
     */
    public static function guess(string $fileName, array $programs): ?string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $compactName = self::compact($name);
        $nameNumbers = array_map(self::withoutLeadingZeros(...), self::numbers($name));

        $matches = array_values(array_filter($programs, function (array $program) use ($compactName, $nameNumbers): bool {
            $poSj = trim((string) ($program['po_sj_number'] ?? ''));

            if ($poSj === '') {
                return false;
            }

            // 1. Nomor PO atau SJ lengkap tertulis di nama file ("PO/2026/0103 / SJ-0103" dicek per bagian)
            foreach (preg_split('#\s+/\s+#', $poSj) as $part) {
                $compactPart = self::compact($part);

                if (strlen($compactPart) >= 4 && preg_match('/'.preg_quote($compactPart, '/').'(?!\d)/', $compactName)) {
                    return true;
                }
            }

            // 2. Nomor urut terakhir PO (misal 0103) muncul sebagai angka utuh di nama file
            $poNumbers = self::numbers($poSj);
            $lastNumber = end($poNumbers);

            return $lastNumber !== false
                && strlen($lastNumber) >= 3
                && in_array(self::withoutLeadingZeros($lastNumber), $nameNumbers, true);
        }));

        return count($matches) === 1 ? (string) $matches[0]['id'] : null;
    }

    private static function compact(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }

    /**
     * @return list<string>
     */
    private static function numbers(string $value): array
    {
        preg_match_all('/\d+/', $value, $matches);

        return $matches[0];
    }

    private static function withoutLeadingZeros(string $number): string
    {
        return ltrim($number, '0') ?: '0';
    }
}

<?php

namespace App\Services;

use Exception;
use Generator;
use XMLReader;
use ZipArchive;

class XlsxReader
{
    /**
     * @return array<int, string>
     */
    public function sharedStrings(string $filePath): array
    {
        $sharedStrings = [];
        $xml = new XMLReader;
        $uri = 'zip://'.realpath($filePath).'#xl/sharedStrings.xml';

        if (! @$xml->open($uri)) {
            return [];
        }

        try {
            while ($xml->read()) {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'si') {
                    $sub = $xml->readOuterXml();
                    preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $sub, $matches);
                    $sharedStrings[] = html_entity_decode(implode('', $matches[1] ?? []), ENT_QUOTES | ENT_XML1);
                }
            }
        } finally {
            $xml->close();
        }

        return $sharedStrings;
    }

    /**
     * @return array<string, string> Sheet name => path inside the xlsx zip
     */
    public function sheets(string $filePath): array
    {
        $zip = new ZipArchive;
        $openResult = $zip->open($filePath);

        if ($openResult !== true) {
            throw new Exception("Gagal membuka arsip Excel: error code {$openResult}");
        }

        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->close();

        if ($workbook === false || $rels === false) {
            throw new Exception('Workbook Excel tidak lengkap');
        }

        $workbookXml = simplexml_load_string($workbook);
        $relsXml = simplexml_load_string($rels);

        if ($workbookXml === false || $relsXml === false) {
            throw new Exception('Workbook Excel tidak dapat dibaca');
        }

        $ridToTarget = [];
        foreach ($relsXml->Relationship as $relationship) {
            $ridToTarget[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $sheets = [];
        $workbookXml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        foreach ($workbookXml->sheets->sheet as $sheet) {
            $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = (string) $attributes['id'];
            $target = $ridToTarget[$rid] ?? '';
            $target = ltrim($target, '/');

            if (! str_starts_with($target, 'xl/')) {
                $target = 'xl/'.$target;
            }

            $sheets[(string) $sheet['name']] = $target;
        }

        return $sheets;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return Generator<int, array<string, string>>
     */
    public function iterateRows(string $filePath, string $sheetPath, array $sharedStrings): Generator
    {
        $xml = new XMLReader;
        $uri = 'zip://'.realpath($filePath).'#'.$sheetPath;

        if (! $xml->open($uri)) {
            throw new Exception("Tidak dapat membaca sheet {$sheetPath}");
        }

        try {
            while ($xml->read()) {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'row') {
                    yield $this->parseRow($xml->readOuterXml(), $sharedStrings);
                }
            }
        } finally {
            $xml->close();
        }
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<string, string>
     */
    public function parseRow(string $rowXml, array $sharedStrings): array
    {
        $cells = [];

        if (preg_match('/<row\b[^>]*\sr="(\d+)"/i', $rowXml, $rowMatch)) {
            $cells['_row'] = $rowMatch[1];
        }

        // Sel kosong bisa ditulis self-closing (<c r="J2" s="22"/>, umum di export Google Sheets);
        // tanpa alternatif "/>" isinya akan terambil dari sel berikutnya.
        preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attributes = $match[1];
            $inner = $match[2] ?? '';

            if (! preg_match('/\br="([A-Z]+)\d+"/', $attributes, $refMatch)) {
                continue;
            }

            $type = '';
            if (preg_match('/\bt="([^"]+)"/', $attributes, $typeMatch)) {
                $type = $typeMatch[1];
            }

            $value = '';

            if ($type === 'inlineStr') {
                preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $inner, $textMatches);
                $value = html_entity_decode(implode('', $textMatches[1] ?? []), ENT_QUOTES | ENT_XML1);
            } elseif (preg_match('/<v>([^<]*)<\/v>/', $inner, $valueMatch)) {
                $value = $valueMatch[1];

                if ($type === 's' && $value !== '' && isset($sharedStrings[(int) $value])) {
                    $value = $sharedStrings[(int) $value];
                }
            }

            if ($value !== '') {
                $cells[$refMatch[1]] = $value;
            }
        }

        return $cells;
    }

    public function normalizeIdentifier(string $value): string
    {
        $value = trim($value);

        if ($value === '' || $value === '-') {
            return '';
        }

        if (is_numeric($value)) {
            if (str_contains(strtoupper($value), 'E')) {
                return sprintf('%.0f', (float) $value);
            }

            if (str_contains($value, '.')) {
                return rtrim(rtrim($value, '0'), '.');
            }
        }

        return $value;
    }
}

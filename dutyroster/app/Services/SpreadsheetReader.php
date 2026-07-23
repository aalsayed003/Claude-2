<?php
namespace App\Services;

/**
 * Minimal spreadsheet reader for CSV and XLSX — no external libraries.
 * Returns rows as associative arrays keyed by a normalised header name.
 */
class SpreadsheetReader
{
    /**
     * @return array{0: string[], 1: array<int,array<string,string>>}
     *         [ headers, rows ]  where each row maps header => cell value.
     */
    public static function read(string $path, string $ext): array
    {
        $ext = strtolower($ext);
        $matrix = $ext === 'csv' ? self::readCsv($path) : self::readXlsx($path);

        // First non-empty row is the header.
        $headers = [];
        $start = 0;
        foreach ($matrix as $i => $row) {
            if (array_filter($row, fn($v) => trim((string) $v) !== '')) {
                $headers = array_map([self::class, 'normalizeHeader'], $row);
                $start = $i + 1;
                break;
            }
        }

        $rows = [];
        for ($i = $start; $i < count($matrix); $i++) {
            $raw = $matrix[$i];
            if (!array_filter($raw, fn($v) => trim((string) $v) !== '')) {
                continue; // skip blank lines
            }
            $assoc = [];
            foreach ($headers as $c => $h) {
                if ($h === '') continue;
                $assoc[$h] = isset($raw[$c]) ? trim((string) $raw[$c]) : '';
            }
            $rows[] = $assoc;
        }
        return [array_values(array_filter($headers)), $rows];
    }

    private static function normalizeHeader(string $h): string
    {
        $h = strtolower(trim($h));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h);
        return trim($h, '_');
    }

    private static function readCsv(string $path): array
    {
        $out = [];
        if (($fh = fopen($path, 'r')) === false) {
            return $out;
        }
        $first = true;
        while (($data = fgetcsv($fh, 0, ',')) !== false) {
            if ($first) {
                // Strip UTF-8 BOM from the very first cell.
                if (isset($data[0])) {
                    $data[0] = preg_replace('/^\x{FEFF}/u', '', $data[0]);
                }
                $first = false;
            }
            $out[] = $data;
        }
        fclose($fh);
        return $out;
    }

    private static function readXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not open the .xlsx file.');
        }

        // Shared strings (concatenate <t> within each <si> for rich text).
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            if (preg_match_all('/<si>(.*?)<\/si>/s', $ss, $m)) {
                foreach ($m[1] as $si) {
                    preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
                    $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }

        // First worksheet.
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            // Fall back to whatever the workbook points at.
            for ($i = 1; $i <= 20; $i++) {
                $sheetXml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if ($sheetXml !== false) break;
            }
        }
        $zip->close();
        if ($sheetXml === false) {
            throw new \RuntimeException('No worksheet found in the .xlsx file.');
        }

        $matrix = [];
        if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheetXml, $rowM)) {
            foreach ($rowM[1] as $rowXml) {
                $cells = [];
                if (preg_match_all('/<c\b([^>]*)(?:\/>|>(.*?)<\/c>)/s', $rowXml, $cm, PREG_SET_ORDER)) {
                    foreach ($cm as $c) {
                        $attrs = $c[1];
                        $inner = $c[2] ?? '';
                        preg_match('/r="([A-Z]+)\d+"/', $attrs, $rm);
                        $col = isset($rm[1]) ? self::colIndex($rm[1]) : count($cells);
                        $type = preg_match('/t="([^"]+)"/', $attrs, $tm) ? $tm[1] : '';
                        $val = '';
                        if ($type === 'inlineStr') {
                            if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $inner, $im)) {
                                $val = implode('', $im[1]);
                            }
                        } elseif (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                            $val = $vm[1];
                            if ($type === 's') {
                                $val = $shared[(int) $val] ?? '';
                            }
                        }
                        $cells[$col] = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                }
                // Fill gaps so columns line up with headers.
                $max = $cells ? max(array_keys($cells)) : -1;
                $line = [];
                for ($i = 0; $i <= $max; $i++) {
                    $line[$i] = $cells[$i] ?? '';
                }
                $matrix[] = $line;
            }
        }
        return $matrix;
    }

    /** "A" -> 0, "B" -> 1, ... "AA" -> 26. */
    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}

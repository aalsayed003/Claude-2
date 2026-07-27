<?php
namespace App\Services;

/**
 * Minimal spreadsheet reader for CSV and XLSX — no external libraries.
 *
 * read()        -> [headers, assoc-rows] keyed by normalised header (used by
 *                  the employee import, where the layout is header-per-column).
 * matrix()      -> raw 2D grid of the first worksheet (used by the roster
 *                  import, where days are columns and position matters).
 * namedMatrix() -> raw 2D grid of a worksheet resolved by its tab name (used
 *                  to read the hidden "Meta" sheet on the roster template).
 */
class SpreadsheetReader
{
    /**
     * @return array{0: string[], 1: array<int,array<string,string>>}
     *         [ headers, rows ]  where each row maps header => cell value.
     */
    public static function read(string $path, string $ext): array
    {
        $matrix = self::matrix($path, $ext);

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

    /** Raw 2D grid of the first worksheet (or the CSV), zero-indexed and packed. */
    public static function matrix(string $path, string $ext): array
    {
        return array_values(self::matrixIndexed($path, $ext));
    }

    /**
     * Like matrix(), but preserved by *real* spreadsheet row number
     * (1-based). Excel omits empty rows, so positional indexing would drift;
     * the roster import needs true row numbers to point at the right cells.
     *
     * @return array<int,array<int,string>>  rowNumber => [colIndex => value]
     */
    public static function matrixIndexed(string $path, string $ext): array
    {
        if (strtolower($ext) === 'csv') {
            $out = [];
            foreach (self::readCsv($path) as $i => $line) {
                $out[$i + 1] = $line;   // CSV: line N -> row N
            }
            return $out;
        }
        return self::readXlsx($path);
    }

    /**
     * Raw 2D grid of the worksheet whose tab is named $name (xlsx only).
     * Returns [] if the workbook has no such sheet.
     */
    public static function namedMatrix(string $path, string $name): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        $target = self::resolveSheetTarget($zip, $name);
        if ($target === null) {
            $zip->close();
            return [];
        }
        $shared  = self::sharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/' . ltrim($target, '/'));
        $zip->close();
        return $sheetXml === false ? [] : array_values(self::parseSheet($sheetXml, $shared));
    }

    // ---------------------------------------------------------------

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
        $shared = self::sharedStrings($zip);

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            for ($i = 1; $i <= 20; $i++) {
                $sheetXml = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if ($sheetXml !== false) break;
            }
        }
        $zip->close();
        if ($sheetXml === false) {
            throw new \RuntimeException('No worksheet found in the .xlsx file.');
        }
        return self::parseSheet($sheetXml, $shared);
    }

    /** Load xl/sharedStrings.xml into an indexed array. */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $shared = [];
        if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            if (preg_match_all('/<si>(.*?)<\/si>/s', $ss, $m)) {
                foreach ($m[1] as $si) {
                    preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
                    $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }
        return $shared;
    }

    /** Resolve a sheet tab name to its worksheet part target, e.g. "worksheets/sheet3.xml". */
    private static function resolveSheetTarget(\ZipArchive $zip, string $name): ?string
    {
        $wb   = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            return null;
        }
        // sheet name -> r:id
        $rid = null;
        if (preg_match_all('/<sheet\b([^>]*)\/?>/i', $wb, $sm)) {
            foreach ($sm[1] as $attrs) {
                if (preg_match('/name="([^"]*)"/', $attrs, $nm)
                    && strcasecmp(html_entity_decode($nm[1], ENT_QUOTES | ENT_XML1, 'UTF-8'), $name) === 0
                    && preg_match('/r:id="([^"]+)"/', $attrs, $rm)) {
                    $rid = $rm[1];
                    break;
                }
            }
        }
        if ($rid === null) {
            return null;
        }
        // r:id -> target
        if (preg_match_all('/<Relationship\b([^>]*)\/?>/i', $rels, $lm)) {
            foreach ($lm[1] as $attrs) {
                if (preg_match('/Id="' . preg_quote($rid, '/') . '"/', $attrs)
                    && preg_match('/Target="([^"]+)"/', $attrs, $tm)) {
                    return $tm[1];
                }
            }
        }
        return null;
    }

    /**
     * Parse a worksheet XML string into a matrix keyed by the real spreadsheet
     * row number (from each <row r="N">), so callers can report true cell
     * references. Rows without an explicit r fall back to sequential numbering.
     *
     * @return array<int,array<int,string>>  rowNumber => [colIndex => value]
     */
    private static function parseSheet(string $sheetXml, array $shared): array
    {
        $matrix = [];
        $seq = 0;
        if (preg_match_all('/<row([^>]*)>(.*?)<\/row>/s', $sheetXml, $rowM, PREG_SET_ORDER)) {
            foreach ($rowM as $rowMatch) {
                $rowAttrs = $rowMatch[1];
                $rowXml   = $rowMatch[2];
                $rowNo    = preg_match('/\br="(\d+)"/', $rowAttrs, $rn) ? (int) $rn[1] : ++$seq;
                $seq      = max($seq, $rowNo);
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
                $max = $cells ? max(array_keys($cells)) : -1;
                $line = [];
                for ($i = 0; $i <= $max; $i++) {
                    $line[$i] = $cells[$i] ?? '';
                }
                $matrix[$rowNo] = $line;
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

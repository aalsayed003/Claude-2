<?php
namespace App\Roster\Services;

/**
 * Minimal .xlsx writer — no external libraries, just ZipArchive + XML.
 *
 * Supports exactly what the duty-roster template needs: multiple sheets
 * (some hidden), inline-string / numeric cells, per-sheet column widths,
 * merged cells, a frozen header, a small fixed set of cell styles, and
 * list data-validation (the shift dropdowns) that points at a range on a
 * hidden "Lists" sheet.
 *
 * Style indices (see stylesXml()):
 *   0 default        1 title (bold 14)     2 header (white bold on blue)
 *   3 grey bold      4 day cell (centred)  5 grey normal
 *
 * Rows and columns are 1-based in the public API.
 */
class XlsxWriter
{
    /** @var array<int,array> */
    private array $sheets = [];

    /** Add a sheet, returns its index for later setCell()/setCols() calls. */
    public function addSheet(string $name, bool $hidden = false): int
    {
        $this->sheets[] = [
            'name'     => $name,
            'hidden'   => $hidden,
            'rows'     => [],   // [rowNo][colNo] => ['v'=>, 's'=>, 't'=>]
            'cols'     => [],   // [ [min,max,width], ... ]
            'merges'   => [],   // ['A1:Z1', ...]
            'valids'   => [],   // [ ['sqref'=>, 'formula1'=>], ... ]
            'freeze'   => null, // ['x'=>int,'y'=>int]
            'maxCol'   => 0,
        ];
        return count($this->sheets) - 1;
    }

    /** @param array<int,array{0:int,1:int,2:int|float}> $cols  list of [min,max,width] */
    public function setCols(int $sheet, array $cols): void
    {
        $this->sheets[$sheet]['cols'] = $cols;
    }

    public function setFreeze(int $sheet, int $xSplit, int $ySplit): void
    {
        $this->sheets[$sheet]['freeze'] = ['x' => $xSplit, 'y' => $ySplit];
    }

    public function addMerge(int $sheet, string $ref): void
    {
        $this->sheets[$sheet]['merges'][] = $ref;
    }

    /** List (dropdown) validation over $sqref, e.g. formula1 = "Lists!$A$2:$A$12". */
    public function addValidationList(int $sheet, string $sqref, string $formula1): void
    {
        $this->sheets[$sheet]['valids'][] = ['sqref' => $sqref, 'formula1' => $formula1];
    }

    /**
     * Set a cell. $type: 'auto' (numbers -> numeric, everything else text),
     * 's' (force text), 'n' (force numeric).
     */
    public function setCell(int $sheet, int $row, int $col, $value, int $style = 0, string $type = 'auto'): void
    {
        if ($type === 'auto') {
            $type = (is_int($value) || is_float($value)) ? 'n' : 's';
        }
        $this->sheets[$sheet]['rows'][$row][$col] = ['v' => $value, 's' => $style, 't' => $type];
        if ($col > $this->sheets[$sheet]['maxCol']) {
            $this->sheets[$sheet]['maxCol'] = $col;
        }
    }

    /** Build the .xlsx into a string. */
    public function build(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsxw');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the .xlsx archive.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        foreach ($this->sheets as $i => $s) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($s, $i === 0));
        }
        $zip->close();

        $data = file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    /** Stream the workbook to the browser as a download and exit. */
    public function stream(string $filename): void
    {
        $data = $this->build();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo $data;
        exit;
    }

    /** 1 -> "A", 27 -> "AA". */
    public static function colLetter(int $col): string
    {
        $s = '';
        while ($col > 0) {
            $m = ($col - 1) % 26;
            $s = chr(65 + $m) . $s;
            $col = intdiv($col - 1, 26);
        }
        return $s;
    }

    // --- XML parts ---------------------------------------------------------

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypesXml(): string
    {
        $overrides = '';
        foreach ($this->sheets as $i => $s) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $s) {
            $state = $s['hidden'] ? ' state="hidden"' : '';
            $sheets .= '<sheet name="' . $this->esc($s['name']) . '" sheetId="' . ($i + 1) . '"' . $state
                . ' r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    private function workbookRelsXml(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $s) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $styleId = count($this->sheets) + 1;
        $rels .= '<Relationship Id="rId' . $styleId . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF2F5597"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
            . '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/>'
            . '</border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function sheetXml(array $s, bool $first): string
    {
        // sheetViews (+ frozen pane)
        $view = '<sheetView workbookViewId="0"' . ($first ? ' tabSelected="1"' : '') . '>';
        if ($s['freeze']) {
            $top = self::colLetter($s['freeze']['x'] + 1) . ($s['freeze']['y'] + 1);
            $view .= '<pane xSplit="' . $s['freeze']['x'] . '" ySplit="' . $s['freeze']['y'] . '" '
                . 'topLeftCell="' . $top . '" activePane="bottomRight" state="frozen"/>';
        }
        $view .= '</sheetView>';

        // columns
        $cols = '';
        if ($s['cols']) {
            $cols = '<cols>';
            foreach ($s['cols'] as $c) {
                $cols .= '<col min="' . $c[0] . '" max="' . $c[1] . '" width="' . $c[2] . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        // rows
        ksort($s['rows']);
        $rowsXml = '';
        foreach ($s['rows'] as $rowNo => $cells) {
            ksort($cells);
            $cellsXml = '';
            foreach ($cells as $colNo => $c) {
                $ref = self::colLetter($colNo) . $rowNo;
                $style = $c['s'] ? ' s="' . $c['s'] . '"' : '';
                if ($c['v'] === '' || $c['v'] === null) {
                    $cellsXml .= '<c r="' . $ref . '"' . $style . '/>';
                } elseif ($c['t'] === 'n') {
                    $cellsXml .= '<c r="' . $ref . '"' . $style . '><v>' . $this->esc((string) $c['v']) . '</v></c>';
                } else {
                    $cellsXml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                        . $this->esc((string) $c['v']) . '</t></is></c>';
                }
            }
            $rowsXml .= '<row r="' . $rowNo . '">' . $cellsXml . '</row>';
        }

        // merges
        $merges = '';
        if ($s['merges']) {
            $merges = '<mergeCells count="' . count($s['merges']) . '">';
            foreach ($s['merges'] as $ref) {
                $merges .= '<mergeCell ref="' . $ref . '"/>';
            }
            $merges .= '</mergeCells>';
        }

        // data validations (dropdowns)
        $valids = '';
        if ($s['valids']) {
            $valids = '<dataValidations count="' . count($s['valids']) . '">';
            foreach ($s['valids'] as $v) {
                $valids .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" '
                    . 'sqref="' . $v['sqref'] . '"><formula1>' . $this->esc($v['formula1']) . '</formula1></dataValidation>';
            }
            $valids .= '</dataValidations>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews>' . $view . '</sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . $merges
            . $valids
            . '</worksheet>';
    }
}

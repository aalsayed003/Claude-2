<?php
namespace App\Payroll\Services;

use App\Core\Config;

/**
 * Best-effort OCR of an image attachment using the free Tesseract CLI.
 *
 * This is deliberately non-blocking: if OCR is disabled, the binary is not
 * installed, or the file is not an OCR-able image, we simply return null and
 * the attachment is still stored and viewable. When Tesseract is present the
 * extracted text is saved alongside the request so HR can read/search a
 * scanned sick note without opening the image.
 */
class OcrService
{
    /** Extensions Tesseract can read directly. PDFs are skipped (need a rasteriser). */
    private const OCR_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp'];

    /** @return string|null extracted text, or null when OCR is unavailable/failed. */
    public static function extract(string $absPath): ?string
    {
        if (!Config::get('payroll.ocr.enabled', true)) return null;
        if (!is_file($absPath)) return null;

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::OCR_EXT, true)) return null;

        $bin = (string) Config::get('payroll.ocr.bin', 'tesseract');
        if (!self::binaryAvailable($bin)) return null;

        $lang = (string) Config::get('payroll.ocr.lang', 'eng');
        // tesseract <img> stdout -l <lang>  --> plain text on stdout
        $cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($absPath) . ' stdout'
             . ' -l ' . escapeshellarg($lang) . ' 2>' . (self::isWindows() ? 'NUL' : '/dev/null');
        $out = @shell_exec($cmd);
        if (!is_string($out)) return null;

        $out = trim(preg_replace('/[ \t]+/', ' ', $out));
        return $out === '' ? null : mb_substr($out, 0, 8000);
    }

    private static function binaryAvailable(string $bin): bool
    {
        if (!function_exists('shell_exec')) return false;
        $probe = self::isWindows()
            ? 'where ' . escapeshellarg($bin) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($bin) . ' 2>/dev/null';
        $r = @shell_exec($probe);
        return is_string($r) && trim($r) !== '';
    }

    private static function isWindows(): bool
    {
        return stripos(PHP_OS, 'WIN') === 0;
    }
}

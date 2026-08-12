<?php
namespace App\Payroll\Services;

use App\Core\Config;

/**
 * Stores a supporting document uploaded with a request (e.g. a sick-leave
 * medical note) under the app's storage directory, and runs best-effort OCR
 * on images so the text is readable/searchable without opening the file.
 *
 * Files are validated by extension and size and given an unguessable name.
 * Nothing here is web-served directly — download goes through an auth-gated
 * controller action so only the owner and HR can see the document.
 */
class AttachmentService
{
    /**
     * Handle a single $_FILES entry.
     *
     * @param array  $file    one entry from $_FILES (name/tmp_name/size/error)
     * @param string $subdir  bucket under the upload root, e.g. 'leave'
     * @return array{name:string,path:string,ocr:?string}|array{error:string}|null
     *         null when no file was uploaded; ['error'=>..] on a rejected file.
     */
    public static function store(array $file, string $subdir = 'leave'): ?array
    {
        // Nothing chosen.
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return ['error' => 'Upload failed (code ' . (int) $file['error'] . ').'];
        }

        $orig = (string) ($file['name'] ?? 'document');
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', (array) Config::get('payroll.uploads.allowed_ext',
            ['pdf', 'jpg', 'jpeg', 'png']));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return ['error' => 'File type not allowed. Accepted: ' . implode(', ', $allowed) . '.'];
        }

        $max = (int) Config::get('payroll.uploads.max_bytes', 5 * 1024 * 1024);
        if ((int) ($file['size'] ?? 0) > $max) {
            return ['error' => 'File is larger than ' . round($max / 1048576, 1) . ' MB.'];
        }

        $dir = self::root() . DIRECTORY_SEPARATOR . preg_replace('/[^a-z0-9_-]/i', '', $subdir);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['error' => 'Could not create the upload directory.'];
        }

        $safe = self::token() . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $safe;

        $tmp = (string) ($file['tmp_name'] ?? '');
        $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
        if (!$moved) return ['error' => 'Could not save the uploaded file.'];
        @chmod($dest, 0644);

        return [
            'name' => mb_substr($orig, 0, 255),
            // store a relative path so the record survives a move of the app root
            'path' => $subdir . '/' . $safe,
            'ocr'  => OcrService::extract($dest),
        ];
    }

    /** Absolute path for a stored relative path, or null if it escapes the root. */
    public static function absolutePath(string $relPath): ?string
    {
        $rel = str_replace('\\', '/', $relPath);
        if ($rel === '' || str_contains($rel, '..')) return null;
        $abs = self::root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        return is_file($abs) ? $abs : null;
    }

    /** Stream a stored file to the browser (inline for images/PDF, else download). */
    public static function stream(string $absPath, string $downloadName): void
    {
        $mime = self::mime($absPath);
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($absPath));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
                . '; filename="' . str_replace('"', '', $downloadName) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=0, must-revalidate');
        }
        readfile($absPath);
    }

    private static function mime(string $absPath): string
    {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $m  = $fi ? finfo_file($fi, $absPath) : false;
            if ($fi) finfo_close($fi);
            if (is_string($m) && $m !== '') return $m;
        }
        return match (strtolower(pathinfo($absPath, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    /** The configured upload root (defaults to <app>/storage/uploads). */
    public static function root(): string
    {
        $dir = (string) Config::get('payroll.uploads.dir', '');
        if ($dir !== '') return rtrim($dir, '/\\');
        $base = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        return $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
    }

    private static function token(): string
    {
        return date('Ymd_His') . '_' . bin2hex(random_bytes(6));
    }
}

<?php
/**
 * Generate PWA / home-screen icons from the ASSH logo.
 *
 * Outputs to public/assets/icons:
 *   icon-192.png, icon-512.png       standard (white background, logo centred)
 *   icon-maskable-512.png            extra safe-zone padding for Android masks
 *   apple-touch-180.png              iOS home-screen icon
 *
 * Re-run after changing the logo:  php tools/make_pwa_icons.php
 */
$root = dirname(__DIR__);
$src  = $root . '/public/assets/assh-logo.jpg';
$out  = $root . '/public/assets/icons';
@mkdir($out, 0775, true);

$logo = imagecreatefromjpeg($src);
$lw = imagesx($logo); $lh = imagesy($logo);

/** Compose a square icon of $size with the logo scaled to $coverage of the width. */
function icon(int $size, float $coverage, array $bg, $logo, int $lw, int $lh, string $path): void {
    $im = imagecreatetruecolor($size, $size);
    imagealphablending($im, true);
    imagefill($im, 0, 0, imagecolorallocate($im, $bg[0], $bg[1], $bg[2]));
    $tw = (int) round($size * $coverage);
    $th = (int) round($tw * $lh / $lw);
    $dx = (int) round(($size - $tw) / 2);
    $dy = (int) round(($size - $th) / 2);
    imagecopyresampled($im, $logo, $dx, $dy, 0, 0, $tw, $th, $lw, $lh);
    imagepng($im, $path);
    imagedestroy($im);
    echo "  wrote " . basename($path) . " ({$size}px)\n";
}

$white = [255, 255, 255];
icon(192, 0.82, $white, $logo, $lw, $lh, "$out/icon-192.png");
icon(512, 0.82, $white, $logo, $lw, $lh, "$out/icon-512.png");
icon(512, 0.60, $white, $logo, $lw, $lh, "$out/icon-maskable-512.png"); // 40% safe-zone padding
icon(180, 0.82, $white, $logo, $lw, $lh, "$out/apple-touch-180.png");
imagedestroy($logo);
echo "done.\n";

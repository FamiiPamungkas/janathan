<?php
$font = dirname(__DIR__) . '/node_modules/@phosphor-icons/web/src/regular/Phosphor.ttf';
if (!is_file($font)) {
    fwrite(STDERR, "Font not found: $font\n");
    exit(1);
}
if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "GD extension not available\n");
    exit(1);
}

$size = 180;
$img = imagecreatetruecolor($size, $size);
imagesavealpha($img, true);
$transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
imagefill($img, 0, 0, $transparent);

$teal = imagecolorallocate($img, 20, 184, 166); // #14b8a6
imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $teal);

$white = imagecolorallocate($img, 255, 255, 255);
$glyph = mb_chr(57822, 'UTF-8'); // database

$fontSize = 118;
$bbox = imagettfbbox($fontSize, 0, $font, $glyph);
$gw = $bbox[2] - $bbox[0];
$gh = $bbox[1] - $bbox[5];
$x = (int) (($size - $gw) / 2 - $bbox[0]);
$y = (int) (($size - $gh) / 2 - $bbox[5]);

imagettftext($img, $fontSize, 0, $x, $y, $white, $font, $glyph);

$out = dirname(__DIR__) . '/public/apple-touch-icon.png';
imagepng($img, $out);
imagedestroy($img);
echo "Wrote $out\n";

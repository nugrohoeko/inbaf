<?php
// Original instructional signage; no participant data or external imagery.
$target = dirname(__DIR__).'/storage/app/private/starter';
if (!is_dir($target)) mkdir($target, 0755, true);
$image = imagecreatetruecolor(1280, 720);
$navy = imagecolorallocate($image, 16, 31, 67);
$blue = imagecolorallocate($image, 55, 107, 255);
$cyan = imagecolorallocate($image, 35, 200, 229);
$white = imagecolorallocate($image, 248, 250, 252);
$slate = imagecolorallocate($image, 181, 196, 217);
imagefill($image, 0, 0, $navy);
imagefilledrectangle($image, 80, 75, 1200, 85, $cyan);
imagefilledrectangle($image, 80, 550, 1200, 640, $blue);
$font = 'C:/Windows/Fonts/arial.ttf';
$lines = [[23, 80, 150, 'INBAF  /  MATERI LATIHAN', $cyan], [50, 80, 270, 'PERPUSTAKAAN KOTA', $white], [36, 80, 370, 'Buka Senin - Jumat', $white], [36, 80, 445, 'Pukul 08.00 - 16.00 WIB', $white], [27, 110, 607, 'Harap tenang. Selamat membaca.', $white]];
foreach ($lines as [$size, $x, $y, $text, $color]) imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
imagepng($image, $target.'/library-sign.png');
imagedestroy($image);
echo "Original library signage created.\n";

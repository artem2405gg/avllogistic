<?php
header('Content-Type: image/x-icon');

$size = 32;
$img = imagecreatetruecolor($size, $size);

// Фон — чёрный
$black = imagecolorallocate($img, 10, 10, 10);
imagefill($img, 0, 0, $black);

// Оранжевый квадрат в правом нижнем углу
$orange = imagecolorallocate($img, 245, 158, 11);
imagefilledrectangle($img, 24, 24, 31, 31, $orange);

// Буква A белая
$white = imagecolorallocate($img, 255, 255, 255);
$fontSize = 5;
$text = 'A';
$textWidth = imagefontwidth($fontSize) * strlen($text);
$x = ($size - $textWidth) / 2;
$y = ($size - imagefontheight($fontSize)) / 2;
imagestring($img, $fontSize, (int)$x, (int)$y, $text, $white);

imagepng($img);
imagedestroy($img);
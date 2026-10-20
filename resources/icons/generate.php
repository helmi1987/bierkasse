<?php

// Generates the PNG app icons (beer mug) with GD. Run once; output is committed.
$out = $argv[1];

function col($im, $hex)
{
    return imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
}

function roundedRect($im, $x1, $y1, $x2, $y2, $r, $c)
{
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $c);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $c);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx,$cy]) {
        imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $c);
    }
}

/** Draws the mug in a 64-unit design space mapped to (ox, oy, scale). */
function mug($im, $ox, $oy, $s)
{
    $p = fn ($v) => (int) round($v * $s);
    $dark = col($im, '#b45309');
    $amber = col($im, '#f59e0b');
    $light = col($im, '#fde68a');
    $mid = col($im, '#d97706');
    $foam = col($im, '#fffbeb');
    // handle: outer rounded rect minus inner
    roundedRect($im, $ox + $p(40), $oy + $p(24), $ox + $p(57), $oy + $p(46), $p(8), $dark);
    roundedRect($im, $ox + $p(44), $oy + $p(30), $ox + $p(51), $oy + $p(40), $p(2), imagecolorat($im, 0, 0));
    // glass with border
    roundedRect($im, $ox + $p(10.5), $oy + $p(16.5), $ox + $p(47.5), $oy + $p(57.5), $p(7), $dark);
    roundedRect($im, $ox + $p(13.5), $oy + $p(19.5), $ox + $p(44.5), $oy + $p(54.5), $p(5), $amber);
    roundedRect($im, $ox + $p(19), $oy + $p(27), $ox + $p(23), $oy + $p(49), $p(2), $light);
    roundedRect($im, $ox + $p(35), $oy + $p(27), $ox + $p(39), $oy + $p(49), $p(2), $mid);
    // foam: outline then fill
    foreach ([[$dark, 2.6], [$foam, 0]] as [$c, $g]) {
        imagefilledellipse($im, $ox + $p(19), $oy + $p(22), $p(20 + $g * 2), $p(18 + $g * 2), $c);
        imagefilledellipse($im, $ox + $p(29), $oy + $p(16), $p(22 + $g * 2), $p(20 + $g * 2), $c);
        imagefilledellipse($im, $ox + $p(39), $oy + $p(22), $p(20 + $g * 2), $p(18 + $g * 2), $c);
        roundedRect($im, $ox + $p(10 - $g), $oy + $p(20), $ox + $p(48 + $g), $oy + $p(27 + $g), $p(4), $c);
    }
}

function icon(int $size, bool $maskable, string $file, ?string $bg = null)
{
    $ss = 4;
    $S = $size * $ss;
    $im = imagecreatetruecolor($S, $S);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
    $bgc = col($im, $bg ?? '#fef3c7');
    if ($maskable) {
        imagefill($im, 0, 0, $bgc);
        $scale = $S * 0.62 / 64; // safe zone (80 % circle)
    } else {
        imagealphablending($im, false);
        imagefill($im, 0, 0, $transparent);
        imagealphablending($im, true);
        roundedRect($im, 0, 0, $S - 1, $S - 1, (int) ($S * 0.22), $bgc);
        $scale = $S * 0.80 / 64;
    }
    // handle cut-out uses pixel (0,0) colour: for non-maskable paint the background there temporarily
    $o = ($S - 64 * $scale) / 2;
    if (! $maskable) {
        imagesetpixel($im, 0, 0, $bgc);
    }
    mug($im, (int) $o, (int) ($o + 1 * $scale), $scale);
    if (! $maskable) {
        imagealphablending($im, false);
        imagesetpixel($im, 0, 0, $transparent);
    }
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $size, $size, $S, $S);
    imagepng($dst, $file, 9);
}

icon(192, false, "$out/icon-192.png");
icon(512, false, "$out/icon-512.png");
icon(192, true, "$out/icon-maskable-192.png");
icon(512, true, "$out/icon-maskable-512.png");
icon(180, true, "$out/apple-touch-icon.png");
icon(32, false, "$out/favicon-32.png");
// favicon.ico with an embedded PNG
$png = file_get_contents("$out/favicon-32.png");
unlink("$out/favicon-32.png");
$ico = pack('vvv', 0, 1, 1).pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22).$png;
file_put_contents("$out/favicon.ico", $ico);
echo "ok\n";

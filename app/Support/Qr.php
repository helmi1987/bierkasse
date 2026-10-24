<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Server-side QR codes as SVG (embedded as data: URI, allowed by the CSP img-src).
 * Error correction level H (~30 % of the code may be damaged) and the standard quiet zone of 4 modules,
 * so printed codes still scan when dirty or creased.
 */
class Qr
{
    /** Paths of public/logo.svg (viewBox 0 0 64 64), also drawn in the centre of every QR code. */
    public const LOGO_PATHS = '<path d="M44 24h5a8 8 0 0 1 8 8v6a8 8 0 0 1-8 8h-5v-6h5a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-5z" fill="#b45309"/>'
        .'<rect x="12" y="18" width="34" height="38" rx="6" fill="#f59e0b"/>'
        .'<rect x="12" y="18" width="34" height="38" rx="6" fill="none" stroke="#b45309" stroke-width="3"/>'
        .'<rect x="19" y="27" width="4" height="22" rx="2" fill="#fde68a"/>'
        .'<rect x="35" y="27" width="4" height="22" rx="2" fill="#d97706"/>'
        .'<path d="M10 22c0-5 4-9 9-9 2-4 6-6 10-6s8 2 10 6c5 0 9 4 9 9 0 3-2 5-5 5H15c-3 0-5-2-5-5z" fill="#fffbeb" stroke="#b45309" stroke-width="2.5" stroke-linejoin="round"/>'
        .'<circle cx="22" cy="31" r="1.6" fill="#fef3c7"/>'
        .'<circle cx="29" cy="38" r="1.2" fill="#fef3c7"/>'
        .'<circle cx="26" cy="45" r="1.4" fill="#fef3c7"/>';

    /** Logo box (white padding included) as share of the QR code width without quiet zone. */
    private const LOGO_SHARE = 0.21;

    public static function svg(string $text, int $size = 256): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 4), new SvgImageBackEnd));

        $svg = $writer->writeString($text, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::H());

        // Logo in the centre on a white padded square (level H tolerates ~30 % damage, the box covers < 5 % of the area).
        $modules = Encoder::encode($text, ErrorCorrectionLevel::H(), Encoder::DEFAULT_BYTE_MODE_ENCODING)->getMatrix()->getWidth();
        $box = $modules * self::LOGO_SHARE * $size / ($modules + 8);
        $pad = $box * 0.1;
        $x = ($size - $box) / 2;
        $f = fn (float $v): string => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
        $logo = '<g data-qr-logo="1">'
            .'<rect x="'.$f($x).'" y="'.$f($x).'" width="'.$f($box).'" height="'.$f($box).'" rx="'.$f($box * 0.12).'" fill="#ffffff"/>'
            .'<svg x="'.$f($x + $pad).'" y="'.$f($x + $pad).'" width="'.$f($box - 2 * $pad).'" height="'.$f($box - 2 * $pad).'" viewBox="0 0 64 64">'.self::LOGO_PATHS.'</svg>'
            .'</g>';

        return str_replace('</svg>', $logo.'</svg>', $svg);
    }

    public static function dataUri(string $text, int $size = 256): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($text, $size));
    }
}

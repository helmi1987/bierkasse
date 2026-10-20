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
    public static function svg(string $text, int $size = 256): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 4), new SvgImageBackEnd));

        return $writer->writeString($text, Encoder::DEFAULT_BYTE_MODE_ENCODING, ErrorCorrectionLevel::H());
    }

    public static function dataUri(string $text, int $size = 256): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($text, $size));
    }
}

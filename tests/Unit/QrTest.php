<?php

namespace Tests\Unit;

use App\Support\Qr;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PHPUnit\Framework\TestCase;

class QrTest extends TestCase
{
    public function test_qr_uses_level_h_and_standard_quiet_zone(): void
    {
        $text = 'https://bier.example.com/registrieren?code=durst';
        $render = fn (ErrorCorrectionLevel $level) => (new Writer(new ImageRenderer(new RendererStyle(256, 4), new SvgImageBackEnd)))
            ->writeString($text, Encoder::DEFAULT_BYTE_MODE_ENCODING, $level);

        $this->assertSame($render(ErrorCorrectionLevel::H()), Qr::svg($text));
        $this->assertNotSame($render(ErrorCorrectionLevel::L()), Qr::svg($text));
    }
}

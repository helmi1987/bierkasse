<?php

namespace Tests\Feature;

use App\Support\Qr;
use Tests\TestCase;

class QrLogoTest extends TestCase
{
    public function test_qr_svg_contains_logo_with_white_background_in_centre(): void
    {
        $svg = Qr::svg('https://bier.example.com/registrieren?code=durst', 480);

        $this->assertStringContainsString('data-qr-logo="1"', $svg);
        $this->assertStringContainsString('fill="#f59e0b"', $svg);
        $this->assertMatchesRegularExpression('/<g data-qr-logo="1"><rect x="([\d.]+)" y="\1" width="([\d.]+)" height="\2"[^>]* fill="#ffffff"\/>/', $svg);
        preg_match('/<g data-qr-logo="1"><rect x="([\d.]+)" [^>]*width="([\d.]+)"/', $svg, $m);
        // Centred and at most ~22 % of the QR width.
        $this->assertEqualsWithDelta(240, (float) $m[1] + (float) $m[2] / 2, 0.01);
        $this->assertLessThanOrEqual(0.22 * 480, (float) $m[2]);
        $this->assertTrue((new \DOMDocument)->loadXML($svg));
        $this->assertStringContainsString('base64,'.base64_encode($svg), Qr::dataUri('https://bier.example.com/registrieren?code=durst', 480));
    }
}

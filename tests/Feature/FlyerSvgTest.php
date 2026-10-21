<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\AppSettings;
use App\Support\FlyerSvg;
use App\Support\Qr;
use App\Support\RegistrationLink;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlyerSvgTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/backend/registrierung-qr/flyer-bild';

    private function open(): void
    {
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_PASSWORD);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
    }

    /** Path data of the QR code inside a QR SVG (unique per content). */
    private function qrPath(string $text): string
    {
        preg_match('/<path[^>]* d="([^"]+)"/', Qr::svg($text, 480), $m);

        return $m[1];
    }

    private function assertWellFormed(string $svg): DOMDocument
    {
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($svg), 'SVG is not well-formed XML');
        $this->assertSame('svg', $dom->documentElement->localName);

        return $dom;
    }

    /** Visible text: wrapped lines (<tspan>) joined with a space, texts separated by " | ". */
    private function text(string $svg): string
    {
        $texts = [];
        foreach ($this->assertWellFormed($svg)->getElementsByTagName('text') as $text) {
            $lines = [];
            foreach ($text->getElementsByTagName('tspan') as $tspan) {
                $lines[] = $tspan->textContent;
            }
            $texts[] = $lines !== [] ? implode(' ', $lines) : $text->textContent;
        }

        return implode(' | ', $texts);
    }

    public function test_access_is_admin_only_like_the_qr_page(): void
    {
        $this->actingAs($this->user())->get(self::URL)->assertForbidden();
        $this->actingAs($this->kassier())->get(self::URL)->assertForbidden();
        $this->actingAs($this->admin())->get(self::URL)->assertOk();
    }

    public function test_svg_is_a4_well_formed_and_inline(): void
    {
        $this->open();
        $response = $this->actingAs($this->admin())->get(self::URL)->assertOk();

        $this->assertStringStartsWith('image/svg+xml', (string) $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename="bierkasse-flyer.svg"', $response->headers->get('Content-Disposition'));

        $svg = $response->getContent();
        $dom = $this->assertWellFormed($svg);
        $root = $dom->documentElement;
        $this->assertSame('0 0 210 297', $root->getAttribute('viewBox'));
        $this->assertSame('210mm', $root->getAttribute('width'));
        $this->assertSame('297mm', $root->getAttribute('height'));
        $this->assertStringNotContainsString('<img', $svg);
        $this->assertStringNotContainsString('<?xml', substr($svg, 10), 'nested QR must not repeat the XML declaration');

        $text = $this->text($svg);
        foreach (['Die Bierkasse ist jetzt digital!', 'HIER SCANNEN', route('register'), 'oder im Browser öffnen:', 'Ohne QR-Code: Registrierungspasswort beim Kassier.',
            'Das Bier bleibt übrigens analog.', 'Zum Home-Bildschirm', 'Fragen? Der Kassier hilft gern (am liebsten nach Feierabend).'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function test_download_parameter_sends_attachment(): void
    {
        $this->open();
        $this->actingAs($this->admin())->get(self::URL.'?download=1')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="bierkasse-flyer.svg"');
    }

    public function test_app_name_is_escaped(): void
    {
        $this->open();
        Setting::put(AppSettings::APP_NAME, 'Bier & <Kasse> "WG"');
        Setting::flushCache();

        $svg = $this->actingAs($this->admin())->get(self::URL)->assertOk()->getContent();

        $this->assertStringContainsString('Bier &amp; &lt;Kasse&gt; &quot;WG&quot;', $svg);
        $this->assertStringNotContainsString('<Kasse>', $svg);
        $dom = $this->assertWellFormed($svg);
        $this->assertSame('Bier & <Kasse> "WG"', $dom->getElementsByTagName('text')->item(0)->textContent);
    }

    public function test_qr_contains_flyer_code_only_in_code_mode(): void
    {
        $this->open();
        $this->actingAs($this->admin());
        $withCode = RegistrationLink::url(true);
        $this->assertStringContainsString('flyer=', $withCode);

        $svg = $this->get(self::URL)->assertOk()->getContent();
        $this->assertStringContainsString($this->qrPath($withCode), $svg);
        // The printed URL text never shows the code.
        $this->assertStringNotContainsString(AppSettings::flyerCode(), $svg);
        $this->assertStringNotContainsString('durst', $svg);
        $this->assertStringContainsString('Benutzername und Passwort wählen. Dauert kürzer', $this->text($svg));

        $svg = $this->get(self::URL.'?code=0')->assertOk()->getContent();
        $this->assertStringNotContainsString($this->qrPath($withCode), $svg);
        $this->assertStringContainsString($this->qrPath(route('register')), $svg);
        $this->assertStringContainsString('Das Registrierungspasswort gibt', $this->text($svg));
    }

    public function test_invite_mode_has_no_code_and_no_password_note(): void
    {
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_INVITE);
        $svg = $this->actingAs($this->admin())->get(self::URL)->assertOk()->getContent();

        $this->assertStringContainsString($this->qrPath(route('register')), $svg);
        $this->assertStringContainsString('Einladungslink', $this->text($svg));
        $this->assertStringNotContainsString('Ohne QR-Code', $svg);
    }

    public function test_wrap_breaks_by_words_and_splits_long_words(): void
    {
        $this->assertSame(['Die Bierkasse ist', 'jetzt digital!'], FlyerSvg::wrap('Die Bierkasse ist jetzt digital!', 18));
        $this->assertSame(['abcde', 'fgh', 'xyz'], FlyerSvg::wrap('abcdefgh xyz', 5));
        $this->assertSame(['https://', 'a.test/r'], FlyerSvg::wrap('https://a.test/r', 8, true));
        $this->assertSame([], FlyerSvg::wrap('  ', 10));
    }
}

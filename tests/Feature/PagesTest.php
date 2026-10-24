<?php

namespace Tests\Feature;

use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_and_static_assets(): void
    {
        $this->get('/manifest.webmanifest')->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('start_url', '/konto');

        foreach (['logo.svg', 'favicon.svg', 'favicon.ico', 'icon-192.png', 'icon-512.png', 'icon-maskable-192.png', 'icon-maskable-512.png', 'apple-touch-icon.png', 'css/app.css', 'js/app.js', 'sw.js'] as $file) {
            $this->assertFileExists(public_path($file));
        }
        $this->assertSame([512, 512], array_slice(getimagesize(public_path('icon-512.png')), 0, 2));
    }

    public function test_manifest_short_name_is_the_full_app_name(): void
    {
        config(['app.installed' => false, 'app.name' => 'Bierkasse LUKS']);

        $this->get('/manifest.webmanifest')->assertOk()
            ->assertJsonPath('name', 'Bierkasse LUKS')
            ->assertJsonPath('short_name', 'Bierkasse LUKS');
    }

    public function test_install_hint_is_on_login_and_account_pages(): void
    {
        $this->get('/')->assertOk()->assertSee('data-install-hint', false)->assertSee('als App installieren');
        $this->actingAs($this->user());
        $this->get('/konto')->assertOk()->assertSee('data-install-hint', false);
    }

    public function test_dashboard_shows_deposit_suggestions_in_beers_and_no_limit(): void
    {
        $this->setDefaultLimit(1000);
        $this->actingAs($this->user());

        $this->get('/konto')->assertOk()
            ->assertSee('20 Biere')->assertSee('CHF 30.00')->assertSee('value="30.00"', false)
            ->assertDontSee('Limit:')->assertDontSee('Kein Minus erlaubt');
    }

    public function test_balance_is_shown_in_beers_with_amount_below(): void
    {
        $this->setDefaultLimit(1000);
        $user = $this->user();
        $kassier = $this->admin();
        Ledger::bookDeposit($user, 1200, 'cash', $kassier);
        $this->actingAs($user);
        $this->get('/konto')->assertOk()->assertSee('8 Biere')->assertSee('CHF 12.00');

        Ledger::consume($user, 10);
        $this->get('/konto')->assertOk()->assertSee('2 Biere offen')->assertSee('CHF -3.00');
    }

    public function test_all_pages_render_for_admin_with_data(): void
    {
        $this->setDefaultLimit(10000);
        $admin = $this->admin();
        $user = $this->user();
        Ledger::consume($user, 3);
        Ledger::giveRound($user, [$admin->id], 2, 'Prost!');
        Ledger::reportDeposit($user, 1000, 'twint', 'via Handy');
        Ledger::bookDeposit($admin, 2000, 'cash', $admin);
        Ledger::requestPayout($admin);

        $this->actingAs($admin);
        foreach (['/konto', '/konto/verlauf', '/konto/verlassen', '/konto/einstellungen', '/statistik', '/admin', '/admin/kasse',
            '/admin/kasse?tab=vorschlaege', '/admin/benutzer', '/admin/benutzer?tab=einladungen', '/admin/benutzer?status=left',
            "/admin/benutzer/{$user->id}", '/admin/buchungen', '/admin/buchungen?tab=geschenke', '/admin/buchungen?tab=gaeste',
            '/admin/lager', '/admin/qr-codes', '/admin/qr-codes/kassen-qr', '/admin/gaeste/qr', '/admin/protokoll', '/admin/protokoll?tab=fassungen',
            '/admin/einstellungen', '/admin/sso', '/admin/sso/create', '/admin/registrierung-qr', '/admin/feedback', '/admin/navigation'] as $url) {
            $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_html_has_no_inline_style_or_script_handlers(): void
    {
        $this->actingAs($this->admin());
        foreach (['/konto', '/konto/einstellungen', '/statistik', '/admin', '/admin/einstellungen', '/admin/buchungen', '/admin/benutzer',
            '/admin/kasse?tab=vorschlaege', '/admin/qr-codes', '/admin/protokoll', '/admin/protokoll?tab=fassungen'] as $url) {
            $html = (string) $this->get($url)->getContent();
            $this->assertDoesNotMatchRegularExpression('/\sstyle=|\son[a-z]+=/i', $html, $url);
        }
    }
}

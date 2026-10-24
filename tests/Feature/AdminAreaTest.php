<?php

namespace Tests\Feature;

use App\Livewire\Backend\AuditLogList;
use App\Livewire\Backend\Bookings;
use App\Livewire\Backend\DepositSuggestionsForm;
use App\Livewire\Backend\QrCodes;
use App\Livewire\Backend\Users;
use App\Models\GuestSale;
use App\Models\QrCode;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin area since 1.20.0: /backend → /admin, menu restructure (Entscheid 2026-10-24) and the Livewire pages.
 */
class AdminAreaTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENTS = [Bookings::class, Users::class, DepositSuggestionsForm::class, QrCodes::class, AuditLogList::class];

    public function test_old_backend_urls_redirect_permanently_to_admin(): void
    {
        // Also for guests (they land on the login page afterwards).
        $this->get('/backend')->assertStatus(301)->assertRedirect(url('/admin'));
        $this->get('/backend/kasse?tab=vorschlaege&x=1')->assertStatus(301)->assertRedirect(url('/admin/kasse').'?tab=vorschlaege&x=1');
        $this->get('/backend/benutzer/5')->assertStatus(301)->assertRedirect(url('/admin/benutzer/5'));

        $this->actingAs($this->kassier());
        $this->followingRedirects()->get('/backend/runden')->assertOk()->assertSee('data-testid="bookings-rounds"', false);
        $this->followingRedirects()->get('/backend/protokoll?q=deposit')->assertOk()->assertSee('value="deposit"', false);
    }

    public function test_moved_pages_redirect_to_the_new_tab(): void
    {
        $this->actingAs($this->kassier());
        $moved = [
            '/admin/statistik' => route('stats'),
            '/admin/runden' => route('backend.bookings', ['tab' => 'runden']),
            '/admin/verschenkt' => route('backend.bookings', ['tab' => 'geschenke']),
            '/admin/gaeste' => route('backend.bookings', ['tab' => 'gaeste']),
            '/admin/einladungen' => route('backend.users.index', ['tab' => 'einladungen']),
            '/admin/einzahlungsvorschlaege' => route('backend.cash', ['tab' => 'vorschlaege']),
            '/admin/rechtliches/fassungen?kind=terms' => route('backend.audit', ['tab' => 'fassungen', 'kind' => 'terms']),
            '/admin/verwaltung/qr-codes' => route('backend.qr-codes.index'),
            '/admin/verwaltung/protokoll' => route('backend.audit'),
            '/admin/verwaltung/protokoll/7' => route('backend.audit.show', 7),
            '/admin/gaeste?page=2' => route('backend.bookings', ['tab' => 'gaeste', 'page' => 2]),
        ];
        foreach ($moved as $from => $to) {
            $this->get($from)->assertStatus(301)->assertRedirect($to);
        }
    }

    public function test_menu_per_role_and_label_admin(): void
    {
        $this->actingAs($this->kassier());
        $this->get('/admin')->assertOk()->assertSeeText('Kassier')->assertDontSee(route('backend.settings'))
            ->assertSee(route('backend.bookings'))->assertSee(route('backend.qr-codes.index'))->assertSee(route('backend.audit'))
            ->assertDontSee('href="'.url('/admin/statistik').'"', false);

        $this->actingAs($this->admin());
        $this->get('/admin/einstellungen')->assertOk()->assertSee('>'."\n                        Admin", false)
            ->assertSee(route('backend.maintenance'))->assertSee('id="funktionen"', false)->assertDontSee('Backend');
        $this->get('/admin')->assertOk()->assertDontSee('Backend');
    }

    public function test_livewire_components_require_kassier_or_admin(): void
    {
        $this->actingAs($this->user());
        foreach (self::COMPONENTS as $component) {
            Livewire::test($component)->assertForbidden();
        }

        foreach ([$this->kassier(), $this->admin()] as $account) {
            $this->actingAs($account);
            foreach (self::COMPONENTS as $component) {
                Livewire::test($component)->assertOk();
            }
        }
    }

    public function test_a_demoted_account_cannot_use_an_open_component(): void
    {
        $kassier = $this->kassier();
        $sale = GuestSale::query()->create(['beers' => 1, 'unit_price' => 150, 'amount' => 150, 'method' => 'cash']);
        $this->actingAs($kassier);
        $component = Livewire::withQueryParams(['tab' => 'gaeste'])->test(Bookings::class)->assertOk();

        // Role taken away while the page is open: the next action is refused (boot hook re-checks the role).
        $kassier->forceFill(['role' => 'user'])->save();
        $this->actingAs($kassier->fresh());
        $component->call('cancelGuestSale', $sale->id)->assertForbidden();
        $this->assertSame(1, GuestSale::query()->count());
    }

    public function test_bookings_tabs_and_query_string(): void
    {
        $giver = $this->user(['first_name' => 'Gabi']);
        $anna = $this->user(['first_name' => 'Anna']);
        $this->setDefaultLimit(10000);
        Ledger::giveRound($giver, [$anna->id], 0, 'Prost');
        Ledger::giveBeers($giver, [$anna->id], 2);
        GuestSale::query()->create(['beers' => 3, 'unit_price' => 150, 'amount' => 450, 'method' => 'twint', 'name' => 'Besuch']);

        $this->actingAs($this->kassier());
        Livewire::test(Bookings::class)
            ->assertSet('tab', 'runden')->assertSee('data-testid="bookings-rounds"', false)->assertSee('«Prost»', false)
            ->call('showTab', 'geschenke')->assertSet('tab', 'geschenke')->assertSee('data-testid="bookings-gifts"', false)
            ->call('showTab', 'gaeste')->assertSet('tab', 'gaeste')->assertSee('Besuch')->assertSee('CHF 4.50')
            ->call('showTab', 'bogus')->assertSet('tab', 'runden');
        Livewire::withQueryParams(['tab' => 'geschenke'])->test(Bookings::class)->assertSet('tab', 'geschenke');
        Livewire::withQueryParams(['tab' => 'nonsense'])->test(Bookings::class)->assertSet('tab', 'runden');
        // Tab links work without JavaScript as well.
        $this->get('/admin/buchungen')->assertOk()->assertSee('href="'.route('backend.bookings', ['tab' => 'gaeste']).'"', false)
            ->assertSee('wire:click.prevent="showTab(\'gaeste\')"', false);
    }

    public function test_kassier_sees_switch_states_but_cannot_toggle_them(): void
    {
        $this->actingAs($this->kassier());
        Livewire::test(Bookings::class)->assertSee(__('round.allowed'))->assertDontSee('name="rounds_enabled"', false)
            ->assertDontSee(route('backend.settings'));
        $this->put('/admin/einstellungen/funktionen', $this->featureForm(['rounds_enabled' => '0', 'gifts_enabled' => '0']))->assertForbidden();
        $this->assertTrue(AppSettings::roundsAllowed());
        $this->assertTrue(AppSettings::giftsAllowed());
        // The former switch routes are gone.
        $this->put('/admin/runden', [])->assertStatus(405);
        $this->put('/admin/verschenkt', [])->assertStatus(405);

        $this->actingAs($this->admin());
        Livewire::test(Bookings::class)->assertSee(route('backend.settings').'#funktionen');
        $this->get('/admin/einstellungen')->assertOk()->assertSee('name="rounds_enabled"', false)->assertSee('name="gifts_enabled"', false)
            ->assertSee('name="guest_user_booking"', false)->assertSee('name="guest_enabled"', false)
            ->assertSee('name="guest_password"', false)->assertSee('name="cashbox_qr_enabled"', false);
        $this->put('/admin/einstellungen/funktionen', $this->featureForm(['rounds_enabled' => '0', 'cashbox_qr_enabled' => '1']))
            ->assertSessionHas('status');
        $this->assertFalse(AppSettings::roundsAllowed());
        $this->assertTrue(AppSettings::giftsAllowed());
        $this->assertTrue((bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED));
        // Switching the cashbox QR on creates its first codes (admin path).
        $this->assertTrue(QrCode::query()->where('kind', QrCode::KIND_CASH)->exists());
    }

    public function test_qr_page_shows_management_to_admins_only(): void
    {
        QrCode::ensureFirst(QrCode::KIND_GUEST);
        $this->actingAs($this->kassier());
        Livewire::test(QrCodes::class)->assertSee('data-testid="qr-overview"', false)
            ->assertDontSee('data-testid="qr-admin-guest"', false)->assertDontSee('action="'.route('backend.qr-codes.store').'"', false);
        $this->assertSame(1, QrCode::query()->count());

        $this->actingAs($this->admin());
        Livewire::test(QrCodes::class)->assertSee('data-testid="qr-admin-guest"', false)->assertSee('action="'.route('backend.qr-codes.store').'"', false)
            ->assertSee('data-testid="qr-admin-registration"', false);
    }

    public function test_users_tabs_and_status_filter(): void
    {
        $this->user(['username' => 'aktiver']);
        $this->user(['username' => 'weg', 'status' => 'left']);
        $this->actingAs($this->kassier());
        Livewire::test(Users::class)->assertSee('aktiver')->assertDontSee('weg')
            ->call('showStatus', 'left')->assertSee('weg')->assertDontSee('aktiver')
            ->call('showTab', 'einladungen')->assertSee('data-testid="users-invitations"', false);
        Livewire::withQueryParams(['status' => 'left'])->test(Users::class)->assertSet('status', 'left');
    }

    public function test_livewire_pages_keep_the_csp_and_carry_the_nonce(): void
    {
        $this->actingAs($this->kassier());
        $response = $this->get('/admin/buchungen')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]+)'; style-src 'self' 'nonce-\\1';/", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        preg_match("/'nonce-([^']+)'/", $csp, $m);
        $nonce = $m[1];

        $html = (string) $response->getContent();
        // CSP build of Livewire, script and style tag with the nonce of this response.
        $this->assertMatchesRegularExpression('/<script src="[^"]*\/livewire\.min\.js\?id=[^"]*-csp" nonce="'.preg_quote($nonce, '/').'"[^>]*data-update-uri="[^"]*\/admin\/livewire\/update"/', $html);
        $this->assertStringContainsString('<style nonce="'.$nonce.'" data-livewire-style>', $html);
        $this->assertStringContainsString('data-livewire-page', $html);
        // No full-page live refresh on Livewire pages; polling by the component.
        $this->assertStringNotContainsString('data-live="', $html);
        $this->assertStringContainsString('wire:poll.10s.visible', $html);
        // Every script tag carries the nonce.
        preg_match_all('/<script\b[^>]*>/', $html, $scripts);
        foreach ($scripts[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $tag);
        }

        // Classic pages are unchanged: no Livewire assets, live refresh on.
        $classic = (string) $this->get('/admin/lager')->assertOk()->getContent();
        $this->assertStringNotContainsString('livewire', $classic);
        $this->assertStringContainsString('data-live="', $classic);
    }

    public function test_livewire_update_route_runs_the_admin_middleware(): void
    {
        $route = Route::getRoutes()->getByName('backend.livewire.update');
        $this->assertNotNull($route);
        $this->assertSame('admin/livewire/update', $route->uri());
        $middleware = $route->gatherMiddleware();
        foreach (['web', 'auth', 'active', 'role:kassier'] as $expected) {
            $this->assertContains($expected, $middleware);
        }

        $headers = ['X-Livewire' => '1', 'Accept' => 'application/json'];
        $payload = ['components' => [['snapshot' => '{}', 'updates' => [], 'calls' => []]]];
        $this->postJson('/admin/livewire/update', $payload, $headers)->assertUnauthorized();
        $this->actingAs($this->user());
        $this->postJson('/admin/livewire/update', $payload, $headers)->assertForbidden();
    }
}

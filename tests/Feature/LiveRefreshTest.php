<?php

namespace Tests\Feature;

use App\Models\GuestSale;
use App\Models\Transaction;
use App\Services\Ledger;
use App\Support\LiveFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Live page refresh (public/js/live.js) and undo buttons that expire on the open page.
 */
class LiveRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_fingerprint_endpoint_requires_login(): void
    {
        $this->getJson('/live')->assertUnauthorized();
        $this->get('/live', ['X-Live-Refresh' => '1'])->assertUnauthorized();
        $this->get('/live')->assertRedirect('/');
    }

    public function test_fingerprint_changes_with_data_but_not_by_rendering_pages(): void
    {
        $this->setDefaultLimit(1000);
        $user = $this->user();
        $kassier = $this->kassier();
        $fp = $this->actingAs($user)->getJson('/live')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('fp');
        $this->assertIsString($fp);

        // Rendering pages writes nothing that the fingerprint sees (no endless refresh).
        $this->get('/konto')->assertOk();
        $this->get('/statistik')->assertOk();
        $this->get('/konto/einstellungen')->assertOk();
        $this->actingAs($kassier)->get('/backend')->assertOk();
        $this->get('/backend/kasse')->assertOk();
        $this->assertSame($fp, LiveFingerprint::current());

        Ledger::consume($user, 1);
        $afterConsume = LiveFingerprint::current();
        $this->assertNotSame($fp, $afterConsume);

        // Deleting a row (withdrawn deposit) changes it as well.
        $tx = Ledger::reportDeposit($user, 500, 'cash');
        $withDeposit = LiveFingerprint::current();
        Ledger::cancelDeposit($tx, $user);
        $this->assertNotSame($withDeposit, LiveFingerprint::current());
    }

    public function test_logged_in_pages_carry_live_markers_and_render_for_live_requests(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $response = $this->get('/konto', ['X-Live-Refresh' => '1'])->assertOk()
            ->assertSee('data-live="'.route('live', [], false).'"', false)
            ->assertSee('data-live-fp="'.LiveFingerprint::current().'"', false)
            ->assertSee('<meta name="server-now" content="', false)
            ->assertSee('js/live.js', false)
            ->assertSee('id="main"', false)
            ->assertSee('data-live-keep="alerts"', false)
            ->assertSee('data-testid="balance"', false);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));

        $kassier = $this->kassier();
        Ledger::reportDeposit($user, 500, 'cash');
        // Header badge and sub-tab badge (pending items) are part of the live render.
        $this->actingAs($kassier)->get('/backend', ['X-Live-Refresh' => '1'])->assertOk()
            ->assertSee('data-live="', false)
            ->assertSee('<span class="badge" title="1 offene Posten">1</span>', false);
    }

    public function test_live_request_when_logged_out_gets_401_instead_of_redirect(): void
    {
        $this->get('/konto', ['X-Live-Refresh' => '1'])->assertUnauthorized();
        $this->get('/backend', ['X-Live-Refresh' => '1'])->assertUnauthorized();
        $this->get('/konto')->assertRedirect('/');
    }

    public function test_no_live_refresh_for_guests_or_pages_with_errors(): void
    {
        $this->get('/')->assertOk()->assertDontSee('data-live="', false)->assertSee('<meta name="server-now"', false);

        $this->actingAs($this->user());
        $this->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => 'abc', 'method' => 'cash']))->assertSessionHasErrors('amount');
        // Page after the validation error keeps old input: no live swap for this view.
        $this->get('/konto')->assertOk()->assertDontSee('data-live="', false);
        $this->get('/konto')->assertOk()->assertSee('data-live="', false);
    }

    public function test_undo_items_carry_their_expiry_in_server_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
        $this->setDefaultLimit(1000);
        $user = $this->user();
        $colleague = $this->user();
        $this->actingAs($user);

        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]))->assertSessionHas('status');
        $this->post('/konto/runde', $this->tokened('round', ['recipients' => [$colleague->id]]))->assertSessionHas('status');
        $this->post('/konto/verschenken', $this->tokened('gift', ['gift_recipients' => [$colleague->id], 'gift_quantity' => 1]))->assertSessionHas('status');
        $this->post('/konto/gast', $this->tokened('guest-booking', ['guest_quantity' => 1, 'guest_method' => 'cash']))->assertSessionHas('status');

        $created = Carbon::now()->getTimestampMs();
        $consume = Transaction::query()->where('type', Transaction::TYPE_CONSUMPTION)->firstOrFail();
        $this->assertSame($created + 10 * 60000, $consume->undoExpiresAt()->getTimestampMs());
        $gift = Transaction::query()->where('type', Transaction::TYPE_GIFT)->whereNull('parent_id')->firstOrFail();
        $this->assertSame($created + 5 * 60000, $gift->undoExpiresAt()->getTimestampMs());

        $page = $this->get('/konto')->assertOk()
            ->assertSee('<meta name="server-now" content="'.$created.'">', false)
            ->assertSee('data-undo-group', false);
        // consume, round and guest: 10 minutes; gift: 5 minutes.
        $html = $page->getContent();
        $this->assertSame(3, substr_count($html, 'data-expires="'.($created + 10 * 60000).'"'));
        $this->assertSame(1, substr_count($html, 'data-expires="'.($created + 5 * 60000).'"'));
        $this->assertSame(4, substr_count($html, 'data-undo-group'));

        // After the window the server renders no undo items any more (and still refuses the undo).
        Carbon::setTestNow(Carbon::now()->addMinutes(10)->addSecond());
        $this->get('/konto')->assertOk()->assertDontSee('data-expires="', false)->assertDontSee('data-undo-group', false);
        $this->post("/konto/bier/{$consume->id}/rueckgaengig")->assertSessionHas('error');
        $sale = GuestSale::query()->firstOrFail();
        $this->assertFalse($sale->canBeUndoneBy($user));
        Carbon::setTestNow();
    }

    public function test_live_refresh_is_off_while_totp_setup_secret_is_shown(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/konto/einstellungen')->assertOk()
            ->assertSee('totp-setup', false)
            ->assertDontSee('data-live="', false);

        $this->actingAs($this->withTotp($this->user(['username' => 'anna'])))->get('/konto/einstellungen')->assertOk()
            ->assertSee('data-live="', false);
    }

    public function test_live_refresh_is_off_on_sso_provider_form(): void
    {
        $this->actingAs($this->admin())->get('/backend/sso/create')->assertOk()
            ->assertDontSee('data-live="', false);
    }

    public function test_fingerprint_is_taken_before_the_page_content(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $start = LiveFingerprint::current();

        // A change while the page is being built must not be hidden by the rendered fingerprint.
        \Illuminate\Support\Facades\View::composer('account.dashboard', function () use ($user) {
            Ledger::reportDeposit($user, 500, 'cash');
        });
        $html = $this->get('/konto')->getContent();
        preg_match('/data-live-fp="([^"]+)"/', $html, $m);
        $this->assertSame($start, $m[1]);
        $this->assertNotSame(LiveFingerprint::current(), $m[1]);
    }

    public function test_form_token_keeps_many_tokens_per_form(): void
    {
        $first = \App\Support\FormToken::issue('x');
        for ($i = 0; $i < 40; $i++) {
            \App\Support\FormToken::issue('x');
        }
        $this->assertContains($first, session('form_tokens.x'));
        for ($i = 0; $i < 20; $i++) {
            \App\Support\FormToken::issue('x');
        }
        $this->assertCount(50, session('form_tokens.x'));
        $this->assertNotContains($first, session('form_tokens.x'));
    }
}

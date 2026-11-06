<?php

namespace Tests\Feature;

use App\Models\GuestSale;
use App\Models\Transaction;
use App\Services\Ledger;
use App\Support\FormToken;
use App\Support\LiveFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Live data on the pages (1.37.0): the Livewire components poll themselves (change marker LiveFingerprint);
 * the old full-page live refresh (/live, X-Live-Refresh, swapping the children of <main>) is gone.
 * public/js/live.js still removes undo buttons when their window ends (server time); form tokens stay per tab.
 */
class LivePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_live_refresh_is_gone(): void
    {
        $this->actingAs($this->user());
        $this->get('/live')->assertNotFound();
        $this->getJson('/live')->assertNotFound();

        $html = (string) $this->get('/konto')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-live="', $html);
        $this->assertStringNotContainsString('data-live-fp', $html);
        $this->assertStringNotContainsString('data-live-keep', $html);
        $this->assertStringContainsString('<meta name="server-now"', $html);
        $this->assertStringContainsString('js/live.js', $html);
    }

    public function test_live_refresh_header_is_not_special_any_more(): void
    {
        // Logged out: redirected to the login page like any other page view (no 401 for that header).
        $this->get('/konto', ['X-Live-Refresh' => '1'])->assertRedirect('/');
        $this->get('/admin', ['X-Live-Refresh' => '1'])->assertRedirect('/');
        // JSON requests (e.g. Livewire updates) still get 401.
        $this->getJson('/konto')->assertUnauthorized();
    }

    public function test_change_marker_changes_with_data_but_not_by_rendering_pages(): void
    {
        $this->setDefaultLimit(1000);
        $user = $this->user();
        $kassier = $this->kassier();
        $this->actingAs($user);
        $fp = LiveFingerprint::current();
        $this->assertIsString($fp);

        // Rendering pages writes nothing that the marker sees (no endless re-render).
        $this->get('/konto')->assertOk();
        $this->get('/statistik')->assertOk();
        $this->get('/konto/einstellungen')->assertOk();
        $this->actingAs($kassier)->get('/admin')->assertOk();
        $this->get('/admin/kasse')->assertOk();
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

    public function test_form_token_keeps_many_tokens_per_form(): void
    {
        $first = FormToken::issue('x');
        for ($i = 0; $i < 40; $i++) {
            FormToken::issue('x');
        }
        $this->assertContains($first, session('form_tokens.x'));
        for ($i = 0; $i < 20; $i++) {
            FormToken::issue('x');
        }
        $this->assertCount(50, session('form_tokens.x'));
        $this->assertNotContains($first, session('form_tokens.x'));
    }
}

<?php

namespace Tests\Feature;

use App\Livewire\Account\History;
use App\Livewire\Account\Leave;
use App\Livewire\Stats;
use App\Models\Setting;
use App\Services\Ledger;
use App\Support\AppSettings;
use App\Support\LiveFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * User pages as Livewire components (1.35.0): statistics, history and leaving poll themselves and render only
 * when something changed; settings, feedback, invite and the legal pages have no live refresh at all.
 */
class LiveUserPagesTest extends TestCase
{
    use RefreshDatabase;

    private function assertLivewirePage(string $html): void
    {
        $this->assertStringContainsString('data-livewire-page', $html);
        $this->assertStringNotContainsString('data-live="', $html);
        $this->assertStringContainsString('wire:poll.10s.visible="tick"', $html);
    }

    public function test_statistics_is_a_livewire_page_that_keeps_its_period(): void
    {
        $this->actingAs($this->user());
        $html = (string) $this->get('/statistik')->assertOk()->assertSeeLivewire(Stats::class)->getContent();
        $this->assertLivewirePage($html);
        $this->assertStringContainsString('data-testid="stats"', $html);
        $this->assertStringContainsString('data-testid="period-bar"', $html);

        $week = (string) $this->get('/statistik?zeitraum=woche')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a class="btn small primary" href="[^"]*zeitraum=woche"\s+aria-current="page"\s*>/', $week);
        $this->assertStringContainsString('&quot;zeitraum&quot;:&quot;woche&quot;', $week);
        Livewire::test(Stats::class, ['zeitraum' => 'woche'])->assertSet('zeitraum', 'woche')
            ->call('tick')->assertSet('zeitraum', 'woche');

        // Custom period: the date form survives a poll (wire:ignore).
        $custom = (string) $this->get('/statistik?zeitraum=frei&von=2026-01-01&bis=2026-01-31')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-testid="period-form" wire:ignore>/', $custom);
        $this->assertStringContainsString('value="2026-01-31"', $custom);

        // Odd query values are ignored like before (all time).
        $odd = (string) $this->get('/statistik?zeitraum[]=woche')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/href="[^"]*\/statistik"\s+aria-current="page"\s*>Gesamt</', $odd);
    }

    public function test_statistics_tick_renders_only_when_something_changed(): void
    {
        $user = $this->user();
        $this->setPrice(150);
        $this->setDefaultLimit(10000);
        $this->actingAs($user);

        $component = Livewire::test(Stats::class, ['zeitraum' => 'monat']);
        $this->assertSame(LiveFingerprint::current(), $component->get('fp'));
        $component->call('tick');
        $this->assertArrayNotHasKey('html', $component->effects);

        Ledger::consume($user, 3);
        $component->call('tick');
        $this->assertSame(LiveFingerprint::current(), $component->get('fp'));
        $this->assertMatchesRegularExpression('/data-testid="stats-total">\s*<div class="kpi big"><span>[^<]+<\/span><strong>3<\/strong>/', $component->effects['html'] ?? '');
        $component->assertSet('zeitraum', 'monat');

        // After the force interval it renders again without a change.
        $component->call('tick');
        $this->assertArrayNotHasKey('html', $component->effects);
        $component->set('renderedAt', time() - Stats::FORCE_SECONDS - 1)->call('tick');
        $this->assertArrayHasKey('html', $component->effects);
    }

    public function test_statistics_switched_off_is_404_for_page_and_component(): void
    {
        Setting::put(AppSettings::STATS_ENABLED, false);
        $this->actingAs($this->user());
        $this->get('/statistik')->assertNotFound();
        Livewire::test(Stats::class)->assertNotFound();
    }

    public function test_history_is_a_livewire_page_with_pagination_in_the_url(): void
    {
        $user = $this->user();
        $this->setDefaultLimit(100000);
        for ($i = 0; $i < 30; $i++) {
            Ledger::consume($user, 1);
        }
        $this->actingAs($user);

        $html = (string) $this->get('/konto/verlauf')->assertOk()->assertSeeLivewire(History::class)->getContent();
        $this->assertLivewirePage($html);
        $this->assertSame(25, substr_count($html, 'class="tx-date"'));
        $this->assertStringContainsString('wire:click="nextPage(\'page\')"', $html);

        // A classic link to page 2 still works.
        $page2 = (string) $this->get('/konto/verlauf?page=2')->assertOk()->getContent();
        $this->assertSame(5, substr_count($page2, 'class="tx-date"'));

        $component = Livewire::test(History::class);
        $this->assertSame(25, substr_count($component->html(), 'class="tx-date"'));
        $component->call('nextPage');
        $this->assertSame(5, substr_count($component->html(), 'class="tx-date"'));
    }

    public function test_history_tick_renders_only_when_something_changed(): void
    {
        $user = $this->user();
        $this->setPrice(150);
        $this->setDefaultLimit(10000);
        $this->actingAs($user);

        $component = Livewire::test(History::class);
        $component->call('tick');
        $this->assertArrayNotHasKey('html', $component->effects);

        Ledger::consume($user, 2);
        $component->call('tick');
        $html = $component->effects['html'] ?? '';
        $this->assertStringContainsString('<strong data-testid="history-balance">'.chf(-300).'</strong>', $html);
        $this->assertSame(1, substr_count($html, 'class="tx-date"'));

        $component->set('assets', 'old')->call('tick')->assertDispatched('assets-changed');
    }

    public function test_history_and_leave_need_a_beer_account(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['admin_only' => true])->save();
        $this->actingAs($admin);
        Livewire::test(History::class)->assertForbidden();
        Livewire::test(Leave::class)->assertForbidden();
    }

    public function test_leave_page_keeps_the_form_outside_of_the_island(): void
    {
        $user = $this->user();
        $this->setPrice(150);
        $this->setDefaultLimit(10000);
        Ledger::confirmDeposit(Ledger::reportDeposit($user, 1000, 'cash'), $this->kassier());
        $this->actingAs($user);

        $html = (string) $this->get('/konto/verlassen')->assertOk()->assertSeeLivewire(Leave::class)->getContent();
        $this->assertLivewirePage($html);
        $this->assertStringContainsString('name=status|', $html);
        $this->assertStringContainsString('name="choice"', $html);
        $this->assertDoesNotMatchRegularExpression('/name=status\|.*name="choice".*ENDFRAGMENT:type=island\|name=status\|/s', $html);
        $this->assertStringContainsString(chf(1000), $html);

        $component = Livewire::test(Leave::class);
        $component->call('tick');
        $this->assertArrayNotHasKey('islandFragments', $component->effects);
        $this->assertArrayNotHasKey('html', $component->effects);

        // The balance changes: only the island comes back; the form is not part of the answer.
        Ledger::consume($user, 2);
        $component->call('tick');
        $this->assertArrayNotHasKey('html', $component->effects);
        $fragments = implode('', $component->effects['islandFragments'] ?? []);
        $this->assertStringContainsString('name=status|', $fragments);
        $this->assertStringContainsString(chf(700), $fragments);
        $this->assertStringNotContainsString('name="choice"', $fragments);

        // A pending deposit blocks leaving: the next poll shows the alert.
        Ledger::reportDeposit($user, 500, 'cash');
        $component->call('tick');
        $this->assertStringContainsString('data-testid="leave-blocked"', implode('', $component->effects['islandFragments'] ?? []));
    }

    public function test_blocked_leave_page_shows_the_alert_and_no_form(): void
    {
        $user = $this->user();
        Ledger::reportDeposit($user, 500, 'cash');
        $html = (string) $this->actingAs($user)->get('/konto/verlassen')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="leave-blocked"', $html);
        $this->assertStringContainsString('Verlassen ist im Moment nicht möglich:', $html);
        $this->assertStringNotContainsString('name="choice"', $html);
    }

    public function test_static_pages_have_no_live_refresh(): void
    {
        $this->actingAs($this->withTotp($this->user()));
        foreach (['/konto/einstellungen', '/konto/feedback', '/konto/einladen', route('legal.privacy', [], false)] as $uri) {
            $html = (string) $this->get($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString('data-live="', $html, $uri);
            $this->assertStringNotContainsString('data-livewire-page', $html, $uri);
        }
    }
}

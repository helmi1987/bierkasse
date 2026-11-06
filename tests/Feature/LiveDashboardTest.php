<?php

namespace Tests\Feature;

use App\Livewire\Account\Dashboard;
use App\Livewire\TopNav;
use App\Models\Setting;
use App\Services\Ledger;
use App\Support\AppSettings;
use App\Support\LiveFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dashboard as Livewire component (1.34.0): polls itself, re-renders only its islands and only when something
 * changed; forms stay as rendered. Top navigation with live badges for kassiers; "Neue Version" event after a deploy.
 */
class LiveDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_is_a_livewire_page_that_polls_itself(): void
    {
        $user = $this->user();
        $html = (string) $this->actingAs($user)->get('/konto')->assertOk()->assertSeeLivewire(Dashboard::class)->getContent();

        $this->assertStringContainsString('data-livewire-page', $html);
        $this->assertStringContainsString('data-update-text="Neue Version verfügbar."', $html);
        $this->assertStringNotContainsString('data-live="', $html);
        $this->assertStringContainsString('wire:poll.10s.visible="tick"', $html);
        $this->assertMatchesRegularExpression('/data-update-uri="[^"]*\/livewire\/update"/', $html);
        // The islands are marked as fragments, the forms sit outside of them.
        foreach (Dashboard::ISLANDS as $island) {
            $this->assertStringContainsString('name='.$island.'|', $html, $island);
        }
        $this->assertStringContainsString('data-testid="balance"', $html);
        $this->assertStringContainsString('name="form_token"', $html);
        // Plain users keep the static navigation (no live data there).
        $this->assertStringNotContainsString('wire:poll.10s.visible="tick" aria-label', $html);
    }

    public function test_tick_renders_the_islands_only_when_something_changed(): void
    {
        $user = $this->user();
        $this->setPrice(150);
        $this->setDefaultLimit(10000);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);
        $this->assertSame(LiveFingerprint::current(), $component->get('fp'));

        // Nothing changed: nothing rendered (no html, no islands).
        $component->call('tick');
        $this->assertArrayNotHasKey('islandFragments', $component->effects);
        $this->assertArrayNotHasKey('html', $component->effects);

        // A booking changes the marker: only the islands come back, with the new balance.
        Ledger::consume($user, 2);
        $component->call('tick');
        $this->assertSame(LiveFingerprint::current(), $component->get('fp'));
        $this->assertArrayNotHasKey('html', $component->effects);
        $fragments = implode('', $component->effects['islandFragments'] ?? []);
        $this->assertStringContainsString('name=balance|', $fragments);
        $this->assertStringContainsString('name=undo-consume|', $fragments);
        $this->assertStringContainsString('data-undo-group', $fragments);
        $this->assertStringContainsString(chf(-300), $fragments);
        // Forms are not part of a poll answer.
        $this->assertStringNotContainsString('name="form_token"', $fragments);
        $this->assertStringNotContainsString('data-round', $fragments);
    }

    public function test_tick_re_renders_after_the_force_interval(): void
    {
        $this->actingAs($this->user());
        $component = Livewire::test(Dashboard::class);
        $component->set('renderedAt', time() - Dashboard::FORCE_SECONDS - 1)->call('tick');
        $this->assertArrayHasKey('islandFragments', $component->effects);
        $this->assertSame(count(Dashboard::ISLANDS), count($component->effects['islandFragments']));
    }

    public function test_deploy_is_announced_once(): void
    {
        $this->actingAs($this->user());
        $component = Livewire::test(Dashboard::class);
        $component->call('tick')->assertNotDispatched('assets-changed');
        $component->set('assets', 'old')->call('tick')->assertDispatched('assets-changed');
        $component->call('tick')->assertNotDispatched('assets-changed');
    }

    public function test_component_needs_a_beer_account(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['admin_only' => true])->save();
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->assertForbidden();
    }

    public function test_top_nav_badges_for_kassiers_poll_and_plain_users_get_the_partial(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        Ledger::reportDeposit($user, 500, 'cash');

        $html = (string) $this->actingAs($kassier)->get('/konto')->assertOk()->assertSeeLivewire(TopNav::class)->getContent();
        $this->assertMatchesRegularExpression('/<nav [^>]*wire:name="top-nav"[^>]*class="nav" aria-label="Hauptnavigation" wire:poll\.10s\.visible="tick">/', $html);
        $this->assertStringContainsString('<span class="badge" title="1 offene Posten">1</span>', $html);

        Livewire::actingAs($kassier)->test(TopNav::class, ['active' => 'account'])
            ->assertSee('title="1 offene Posten"', false)
            ->set('assets', 'old')->call('tick')->assertDispatched('assets-changed');
        Livewire::actingAs($user)->test(TopNav::class)->assertForbidden();

        // Classic pages keep the static navigation with the same markup (settings: no live data, 1.36.0).
        $classic = (string) $this->actingAs($this->admin())->get('/admin/einstellungen')->assertOk()->getContent();
        $this->assertStringContainsString('<nav class="nav" aria-label="Hauptnavigation">', $classic);
        $this->assertStringContainsString('<span class="badge" title="1 offene Posten">1</span>', $classic);
        $this->assertStringNotContainsString('wire:poll', $classic);
    }

    public function test_classic_form_posts_still_open_the_card_with_errors_and_old_input(): void
    {
        $user = $this->user();
        Setting::put(AppSettings::ROUNDS_ENABLED, true);
        $this->user(['first_name' => 'Beat', 'last_name' => 'B']);
        $this->actingAs($user);
        $this->post('/konto/runde', $this->tokened('round', ['recipients' => [], 'extra' => 0, 'round_form' => 1, 'message' => 'Hallo']))
            ->assertSessionHas('error');
        $this->post('/konto/runde', $this->tokened('round', ['recipients' => [], 'extra' => 'abc', 'round_form' => 1, 'message' => 'Hallo']))
            ->assertSessionHasErrors('extra');
        $html = (string) $this->get('/konto')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<section class="card round-card" data-testid="round">\s*<details\s+open\s*>/', $html);
        $this->assertStringContainsString('value="Hallo"', $html);
    }
}

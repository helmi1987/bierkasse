<?php

namespace Tests\Feature;

use App\Livewire\Backend\Cash;
use App\Livewire\Backend\Dashboard;
use App\Livewire\Backend\Disposals as DisposalsPage;
use App\Livewire\Backend\FeedbackList;
use App\Livewire\Backend\Stats as BackendStats;
use App\Livewire\Backend\Stock;
use App\Livewire\Backend\SubNav;
use App\Livewire\Backend\UserShow;
use App\Models\AuditLog;
use App\Models\CashEntry;
use App\Models\Feedback;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit;
use App\Services\BeerStock;
use App\Services\Disposals;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin pages as Livewire components (1.36.0): overview, cash, stock, user detail, disposals, feedback and
 * backend statistics poll themselves and re-render only their islands (statistics: the whole component) when
 * something changed; the sub-navigation badges poll too. Pages without live data have no live refresh at all.
 */
class LiveAdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private function islands(Testable $component): string
    {
        return implode('', $component->effects['islandFragments'] ?? []);
    }

    public function test_admin_pages_are_livewire_pages_with_a_polling_sub_navigation(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $this->actingAs($admin);

        $pages = [
            '/admin' => Dashboard::class,
            '/admin/kasse' => Cash::class,
            '/admin/lager' => Stock::class,
            '/admin/benutzer/'.$user->id => UserShow::class,
            '/admin/entsorgung' => DisposalsPage::class,
            '/admin/feedback' => FeedbackList::class,
            '/admin/statistik' => BackendStats::class,
        ];
        foreach ($pages as $uri => $class) {
            $html = (string) $this->get($uri)->assertOk()->assertSeeLivewire($class)->assertSeeLivewire(SubNav::class)->getContent();
            $this->assertStringContainsString('data-livewire-page', $html, $uri);
            $this->assertStringContainsString('wire:poll.10s.visible="tick"', $html, $uri);
            $this->assertStringNotContainsString('data-live="', $html, $uri);
            $this->assertMatchesRegularExpression('/<nav [^>]*wire:name="backend.sub-nav"[^>]*class="subnav"[^>]*wire:poll\.10s>/', $html, $uri);
            $this->assertStringNotContainsString('<nav class="subnav"', $html, $uri);
        }

        // The other Livewire admin pages use the polling sub-navigation too.
        foreach (['/admin/buchungen', '/admin/benutzer', '/admin/kasse?tab=vorschlaege', '/admin/qr-codes', '/admin/protokoll'] as $uri) {
            $this->get($uri)->assertOk()->assertSeeLivewire(SubNav::class);
        }
    }

    public function test_components_are_forbidden_for_plain_users(): void
    {
        $user = $this->user();
        foreach ([Dashboard::class, Cash::class, Stock::class, DisposalsPage::class, FeedbackList::class, BackendStats::class, SubNav::class] as $class) {
            Livewire::actingAs($user)->test($class)->assertForbidden();
        }
        Livewire::actingAs($user)->test(UserShow::class, ['user' => $user])->assertForbidden();
        // Feedback is for admins only.
        Livewire::actingAs($this->kassier())->test(FeedbackList::class)->assertForbidden();
    }

    public function test_sub_navigation_keeps_the_active_tab_and_refreshes_its_badges(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        $html = (string) $this->actingAs($kassier)->get('/admin/kasse')->assertOk()->getContent();
        preg_match('/<nav [^>]*class="subnav".*?<\/nav>/s', $html, $nav);
        $this->assertMatchesRegularExpression('/<a href="[^"]*\/admin\/kasse" class="active">/', $nav[0]);
        $this->assertStringNotContainsString('class="badge"', $nav[0]);

        $component = Livewire::actingAs($kassier)->test(SubNav::class)->set('activeRoute', 'backend.cash');
        Ledger::reportDeposit($user, 500, 'cash');
        $component->call('$refresh');
        $this->assertStringContainsString('title="1 offene Posten"', $component->html());
        $this->assertMatchesRegularExpression('/<a href="[^"]*\/admin\/kasse" class="active">/', $component->html());
    }

    public function test_dashboard_tick_returns_the_deposits_island_only_when_something_changed(): void
    {
        $kassier = $this->kassier();
        $user = $this->user(['first_name' => 'Rita', 'last_name' => 'Rot']);
        $this->actingAs($kassier);

        $html = (string) $this->get('/admin')->assertOk()->getContent();
        foreach (Dashboard::ISLANDS as $island) {
            $this->assertStringContainsString('name='.$island.'|', $html, $island);
        }

        $component = Livewire::actingAs($kassier)->test(Dashboard::class);
        $component->call('tick');
        $this->assertArrayNotHasKey('islandFragments', $component->effects);
        $this->assertArrayNotHasKey('html', $component->effects);

        Ledger::reportDeposit($user, 500, 'cash');
        $component->call('tick');
        $this->assertArrayNotHasKey('html', $component->effects);
        $fragments = $this->islands($component);
        $this->assertStringContainsString('name=deposits|', $fragments);
        $this->assertStringContainsString($user->displayName(), $fragments);
        // Rows are keyed, their forms survive a poll.
        $this->assertStringContainsString('wire:key="deposit-', $fragments);
        $this->assertStringContainsString('<span class="row-buttons" wire:ignore>', $fragments);
    }

    public function test_stock_correction_changes_the_status_island(): void
    {
        $kassier = $this->kassier();
        $this->actingAs($kassier);
        $component = Livewire::actingAs($kassier)->test(Stock::class);
        $component->call('tick');
        $this->assertArrayNotHasKey('islandFragments', $component->effects);

        BeerStock::correct(42, 'Gezählt', $kassier);
        $component->call('tick');
        $fragments = $this->islands($component);
        $this->assertStringContainsString('name=status|', $fragments);
        $this->assertStringContainsString(trans_choice('stock.beers', 42, ['count' => 42]), $fragments);
        $this->assertStringContainsString('name=corrections|', $fragments);
        // The forms are not part of a poll answer.
        $this->assertStringNotContainsString('name="counted"', $fragments);
    }

    public function test_user_show_consume_changes_the_balance_island(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        $this->setPrice(150);
        $this->setDefaultLimit(10000);

        $html = (string) $this->actingAs($kassier)->get('/admin/benutzer/'.$user->id)->assertOk()->getContent();
        foreach (UserShow::ISLANDS as $island) {
            $this->assertStringContainsString('name='.$island.'|', $html, $island);
        }
        $this->assertMatchesRegularExpression('/<form method="post" action="[^"]*\/sperren" wire:ignore/', $html);

        $component = Livewire::actingAs($kassier)->test(UserShow::class, ['user' => $user]);
        $component->call('tick');
        $this->assertArrayNotHasKey('islandFragments', $component->effects);

        Ledger::consume($user, 2);
        $component->call('tick');
        $fragments = $this->islands($component);
        $this->assertStringContainsString('name=balance|', $fragments);
        $this->assertStringContainsString(chf(-300), $fragments);
        $this->assertStringContainsString('wire:key="tx-', $fragments);
        $this->assertStringNotContainsString('name="staff_note"', $fragments);

        // A lock by someone else shows up in the heading and the actions.
        Audit::save('user.lock', $user->forceFill(['status' => User::STATUS_LOCKED, 'locked_at' => now(), 'locked_reason' => 'Test', 'locked_by' => $kassier->id]));
        $component->call('tick');
        $this->assertStringContainsString('data-testid="locked-info"', $this->islands($component));
    }

    public function test_disposal_report_changes_the_list_island(): void
    {
        $kassier = $this->kassier();
        $user = $this->user(['first_name' => 'Edi', 'last_name' => 'Entsorger']);
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => now()->toDateString(), 'amount' => -10000, 'beers' => 100, 'method' => 'cash', 'note' => 'Kauf']);
        Setting::putMany([
            AppSettings::DISPOSAL_ENABLED => true,
            AppSettings::DISPOSAL_ENABLED_AT => now()->format(DATE_ATOM),
        ]);

        $component = Livewire::actingAs($kassier)->test(DisposalsPage::class);
        $component->call('tick');
        $this->assertArrayNotHasKey('islandFragments', $component->effects);

        Disposals::report($user, $user);
        $component->call('tick');
        $fragments = $this->islands($component);
        $this->assertStringContainsString('name=list|', $fragments);
        $this->assertStringContainsString('data-testid="disposal-list"', $fragments);
        $this->assertStringContainsString('Edi Entsorger', $fragments);
        $this->assertStringNotContainsString('data-testid="disposal-settings"', $fragments);
    }

    public function test_new_feedback_changes_the_list_island(): void
    {
        $admin = $this->admin();
        $component = Livewire::actingAs($admin)->test(FeedbackList::class);
        $component->call('tick');
        $this->assertArrayNotHasKey('islandFragments', $component->effects);

        Feedback::query()->create(['user_id' => $this->user()->id, 'type' => 'idea', 'message' => 'Mehr Bier']);
        $component->call('tick');
        $fragments = $this->islands($component);
        $this->assertStringContainsString('name=list|', $fragments);
        $this->assertStringContainsString('Mehr Bier', $fragments);
        $this->assertStringNotContainsString('name="recipient_mode"', $fragments);
    }

    public function test_backend_stats_keep_their_filters_on_tick(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        $this->setPrice(150);
        $this->setDefaultLimit(10000);

        $html = (string) $this->actingAs($kassier)->get('/admin/statistik?zeitraum=monat&typ=runden&gruppe=personen')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="stats-filters" wire:ignore>', $html);
        $this->assertStringContainsString('&quot;typ&quot;:&quot;runden&quot;', $html);

        $component = Livewire::actingAs($kassier)->test(BackendStats::class, ['zeitraum' => 'monat', 'typ' => 'alle'])
            ->assertSet('zeitraum', 'monat')->assertSet('typ', 'alle');
        $component->call('tick');
        $this->assertArrayNotHasKey('html', $component->effects);

        Ledger::consume($user, 3);
        $component->call('tick')->assertSet('zeitraum', 'monat')->assertSet('typ', 'alle');
        $this->assertMatchesRegularExpression('/data-testid="kpi-beers"><span>[^<]+<\/span><strong>3<\/strong>/', $component->effects['html'] ?? '');
    }

    public function test_cash_entries_paginate_inside_the_island(): void
    {
        $kassier = $this->kassier();
        for ($i = 0; $i < 35; $i++) {
            CashEntry::query()->create(['type' => 'income', 'date' => now()->toDateString(), 'amount' => 100, 'method' => 'cash', 'note' => 'Eintrag '.$i]);
        }

        $html = (string) $this->actingAs($kassier)->get('/admin/kasse')->assertOk()->getContent();
        $this->assertSame(Cash::PER_PAGE, substr_count($html, 'wire:key="entry-'));
        $this->assertStringContainsString('wire:click="nextPage(\'page\')"', $html);
        $this->assertSame(5, substr_count((string) $this->get('/admin/kasse?page=2')->getContent(), 'wire:key="entry-'));

        $component = Livewire::actingAs($kassier)->test(Cash::class);
        $this->assertSame(Cash::PER_PAGE, substr_count($component->html(), 'wire:key="entry-'));
        $component->call('nextPage');
        $this->assertSame(5, substr_count($component->html(), 'wire:key="entry-'));
    }

    public function test_static_admin_pages_have_no_live_refresh(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Audit::log('test.entry');
        $entry = AuditLog::query()->latest('id')->firstOrFail();

        $uris = [
            route('backend.settings', [], false),
            route('backend.nav', [], false),
            route('backend.sso.index', [], false),
            route('backend.sso.create', [], false),
            route('backend.maintenance', [], false),
            route('backend.qr-codes.cashbox', [], false),
            route('backend.guests.qr', [], false),
            route('backend.registration.qr', [], false),
            route('backend.audit.show', $entry, false),
        ];
        foreach ($uris as $uri) {
            $html = (string) $this->get($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString('data-live="', $html, $uri);
            $this->assertStringNotContainsString('data-livewire-page', $html, $uri);
        }
    }
}

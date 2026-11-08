<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashEntry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Disposals;
use App\Services\Ledger;
use App\Services\Stats;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Entsorgungsbonus" (1.32.0), statistics: personal KPI, ranking (public and backend) and the separate
 * opt-out switch "Name in Statistik Entsorgung zeigen".
 */
class DisposalStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $kassier;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->setDateTime(2026, 11, 6, 12, 0, 0));
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-11-01', 'amount' => -10000, 'beers' => 100, 'method' => 'cash', 'note' => 'Kauf']);
        $this->kassier = $this->kassier(['first_name' => 'Kurt', 'last_name' => 'Kassier']);
        $this->enable();
    }

    /** Feature on without lock, minimum and withdrawal window (every report is booked at once). */
    private function enable(array $settings = []): void
    {
        Setting::putMany($settings + [
            AppSettings::DISPOSAL_ENABLED => true,
            AppSettings::DISPOSAL_ENABLED_AT => now()->format(DATE_ATOM),
            AppSettings::DISPOSAL_MODE => AppSettings::DISPOSAL_MODE_FIXED,
            AppSettings::DISPOSAL_FIXED => 1,
            AppSettings::DISPOSAL_LOCK_DAYS => 0,
            AppSettings::DISPOSAL_MIN_BEERS => 0,
            AppSettings::UNDO_MINUTES => ['disposal' => 0],
        ]);
    }

    /** Reports $times disposals of $beers each for $user (by the kassier) and books them. */
    private function dispose(User $user, int $times = 1, int $beers = 1): void
    {
        Setting::putMany([AppSettings::DISPOSAL_FIXED => $beers]);
        for ($i = 0; $i < $times; $i++) {
            Disposals::report($user, $this->kassier);
            $this->artisan('disposals:book')->assertSuccessful();
        }
    }

    public function test_kpi_on_dashboard_and_in_for_user(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(100000);
        $user = $this->user(['first_name' => 'Edi', 'last_name' => 'Entsorger']);
        Ledger::consume($user, 2);

        $this->actingAs($user)->get('/konto')->assertOk()->assertDontSee('data-testid="disposals"', false);

        $this->dispose($user, 1, 2);
        $this->dispose($user, 1, 3);

        $stats = Stats::forUser($user);
        $this->assertSame(['count' => 2, 'beers' => 5], $stats['disposals']);
        $this->assertSame(2, $stats['total_beers']);

        $page = $this->actingAs($user)->get('/konto')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-testid="disposals"><span>Entsorgungen<\/span><strong>2<\/strong><small>5 Biere<\/small>/', $page);

        $this->actingAs($this->kassier)->get('/admin/benutzer/'.$user->id)->assertOk()
            ->assertSee('data-testid="disposals"', false)->assertSee('Entsorgungen: 2 · 5 Biere');
    }

    public function test_ranking_sorted_by_count_not_beers_with_competition_ranks(): void
    {
        $a = $this->user(['first_name' => 'Anna', 'last_name' => 'A']);
        $b = $this->user(['first_name' => 'Beat', 'last_name' => 'B']);
        $c = $this->user(['first_name' => 'Cleo', 'last_name' => 'C']);
        $d = $this->user(['first_name' => 'Dani', 'last_name' => 'D']);
        $this->dispose($a, 2, 1);
        $this->dispose($b, 1, 5);
        $this->dispose($c, 1, 2);
        $this->dispose($d, 1, 5);

        $ranking = Disposals::ranking();
        $this->assertSame([$a->id, $b->id, $d->id, $c->id], array_column($ranking, 'user_id'));
        $this->assertSame([1, 2, 2, 2], array_column($ranking, 'rank'));
        $this->assertSame([2, 1, 1, 1], array_column($ranking, 'count'));
        $this->assertSame([2, 5, 5, 2], array_column($ranking, 'beers'));
        $this->assertSame($a->displayName(), $ranking[0]['name']);
        $this->assertFalse($ranking[0]['anonymous']);

        // Cut after place 1 keeps only the leader; after place 2 every row tied with it.
        $this->assertCount(1, Disposals::ranking(1));
        $this->assertCount(4, Disposals::ranking(2));
        $this->assertSame(['count' => 5, 'beers' => 14], Disposals::totals());
    }

    public function test_ranking_skips_accounts_without_beer_account(): void
    {
        $other = $this->kassier(['first_name' => 'Karl', 'last_name' => 'Kassier']);
        $this->dispose($other);
        $this->assertCount(1, Disposals::ranking());
        // "Nur Verwaltung" (kassiers and admins only) is never ranked.
        $other->forceFill(['admin_only' => true])->save();

        $this->assertSame([], Disposals::ranking());
    }

    public function test_public_switch_and_feature_switch(): void
    {
        $user = $this->user(['first_name' => 'Edi', 'last_name' => 'Entsorger']);
        $this->dispose($user, 2);
        $card = 'data-testid="stats-disposal-ranking"';

        // Off (default): not on /statistik, but always on the backend page.
        $this->actingAs($user)->get('/statistik')->assertOk()->assertDontSee($card, false)->assertDontSee(__('disposal.ranking'));
        $this->actingAs($this->kassier)->get('/admin/lager')->assertOk()
            ->assertSee('data-testid="backend-disposal-ranking"', false)->assertSee('Edi Entsorger')->assertSee('2×');

        Setting::putMany([AppSettings::DISPOSAL_RANKING_PUBLIC => true]);
        $page = $this->actingAs($user)->get('/statistik')->assertOk()->assertSee($card, false)
            ->assertSee(__('disposal.ranking'))->assertSee('2×')->assertSee('2 Biere')->getContent();
        $this->assertMatchesRegularExpression('/<li class="me rank-1">\s*<span class="rank">1<\/span>\s*<span class="name">Edi Entsorger<\/span>/', $page);

        // The ranking part of the statistics switched off hides it too.
        Setting::putMany([AppSettings::STATS_SHOW_RANKING => false]);
        $this->actingAs($user)->get('/statistik')->assertOk()->assertDontSee($card, false);
        Setting::putMany([AppSettings::STATS_SHOW_RANKING => true]);

        Setting::putMany([AppSettings::DISPOSAL_ENABLED => false]);
        $this->actingAs($user)->get('/statistik')->assertOk()->assertDontSee($card, false);
    }

    public function test_empty_public_ranking(): void
    {
        Setting::putMany([AppSettings::DISPOSAL_RANKING_PUBLIC => true]);
        $this->actingAs($this->user())->get('/statistik')->assertOk()
            ->assertSee('data-testid="stats-disposal-ranking"', false)->assertSee(__('disposal.ranking_empty'));
    }

    public function test_opt_out_is_anonymous_everywhere_and_independent_of_stats_opt_out(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(100000);
        Setting::putMany([AppSettings::DISPOSAL_RANKING_PUBLIC => true]);
        $hidden = $this->user(['first_name' => 'Heiri', 'last_name' => 'Versteckt', 'disposal_stats_opt_out' => true]);
        $beerHidden = $this->user(['first_name' => 'Bea', 'last_name' => 'Bierscheu', 'stats_opt_out' => true]);
        $viewer = $this->user(['first_name' => 'Vreni', 'last_name' => 'Viewer']);
        $this->dispose($hidden, 2);
        $this->dispose($beerHidden, 1);
        Ledger::consume($beerHidden, 3);

        $ranking = Disposals::ranking();
        $this->assertSame('Anonym 1', $ranking[0]['name']);
        $this->assertTrue($ranking[0]['anonymous']);
        $this->assertSame($hidden->id, $ranking[0]['user_id']);
        $this->assertSame('Bea Bierscheu', $ranking[1]['name']);

        $page = $this->actingAs($viewer)->get('/statistik')->assertOk()->assertDontSee('Heiri')->getContent();
        $this->assertMatchesRegularExpression('/data-testid="disposal-ranking">(?:\s|<!--\[if \w+\]><!\[endif\]-->)*<li class="rank-1">\s*<span class="rank">1<\/span>\s*<span class="name">Anonym 1<\/span>/', $page);
        $this->assertStringContainsString('<span class="name">Bea Bierscheu</span>', $page);
        // Beer ranking: Bea is anonymous there (stats_opt_out), but named in the disposal ranking.
        $this->assertSame('Anonym 1', Stats::global()['ranking'][0]['name']);

        $this->actingAs($this->kassier)->get('/admin/lager')->assertOk()
            ->assertSee('<span class="name">Anonym 1</span>', false)->assertDontSee('<span class="name">Heiri Versteckt</span>', false);
    }

    public function test_user_toggles_disposal_stats_visibility(): void
    {
        $user = $this->user();
        $this->assertFalse($user->fresh()->disposal_stats_opt_out);
        $this->actingAs($user)->get('/konto/einstellungen')->assertOk()
            ->assertSee(__('account.disposal_stats_show'))->assertSee(__('account.stats_show'))
            ->assertSee('action="'.route('account.disposal-stats').'"', false);

        $this->post('/konto/statistik-entsorgung', [])->assertSessionHas('status', __('account.stats_saved'));
        $this->assertTrue($user->fresh()->disposal_stats_opt_out);
        $this->assertFalse($user->fresh()->stats_opt_out);
        $log = AuditLog::query()->where('action', 'user.settings')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertTrue((bool) ($log->newValues()['disposal_stats_opt_out'] ?? null));

        $this->post('/konto/statistik-entsorgung', ['show' => '1']);
        $this->assertFalse($user->fresh()->disposal_stats_opt_out);
        $this->assertFalse($user->fresh()->stats_opt_out);
    }

    /** Feature switched off (1.32.2): opt-out switch and KPI hidden for the user, the route is 404, the value stays. */
    public function test_feature_off_hides_switch_and_kpi_and_route_is_404(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(100000);
        $user = $this->user(['first_name' => 'Edi', 'last_name' => 'Entsorger']);
        $this->dispose($user, 2, 1);
        Setting::putMany([AppSettings::DISPOSAL_ENABLED => false]);

        $this->actingAs($user)->get('/konto/einstellungen')->assertOk()
            ->assertDontSee('data-testid="disposal-stats-form"', false)->assertDontSee(__('account.disposal_stats_show'))
            ->assertSee('action="'.route('account.stats').'"', false)->assertSee('name="show"', false)
            ->assertSee(__('account.stats_show'));
        $this->get('/konto')->assertOk()->assertDontSee('data-testid="disposals"', false);

        $this->post('/konto/statistik-entsorgung', [])->assertNotFound();
        $this->assertFalse($user->fresh()->disposal_stats_opt_out);
    }

    public function test_feature_on_shows_switch_and_kpi(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(100000);
        $user = $this->user(['first_name' => 'Edi', 'last_name' => 'Entsorger']);
        $this->dispose($user, 2, 1);

        $this->actingAs($user)->get('/konto/einstellungen')->assertOk()
            ->assertSee('data-testid="disposal-stats-form"', false)->assertSee(__('account.disposal_stats_show'))
            ->assertSee('action="'.route('account.stats').'"', false)->assertSee(__('account.stats_show'));
        $this->get('/konto')->assertOk()->assertSee('data-testid="disposals"', false);
    }

    public function test_opt_out_survives_feature_off_and_on(): void
    {
        Setting::putMany([AppSettings::DISPOSAL_RANKING_PUBLIC => true]);
        $user = $this->user(['first_name' => 'Heiri', 'last_name' => 'Versteckt']);
        $viewer = $this->user(['first_name' => 'Vreni', 'last_name' => 'Viewer']);
        $this->dispose($user, 2);

        $this->actingAs($user)->post('/konto/statistik-entsorgung', [])->assertSessionHas('status', __('account.stats_saved'));
        $this->assertTrue($user->fresh()->disposal_stats_opt_out);

        Setting::putMany([AppSettings::DISPOSAL_ENABLED => false]);
        $this->assertTrue($user->fresh()->disposal_stats_opt_out);
        $this->enable();
        $this->assertTrue($user->fresh()->disposal_stats_opt_out);

        $page = $this->actingAs($viewer)->get('/statistik')->assertOk()->assertDontSee('Heiri')->getContent();
        $this->assertMatchesRegularExpression('/data-testid="disposal-ranking">(?:\s|<!--\[if \w+\]><!\[endif\]-->)*<li class="rank-1">\s*<span class="rank">1<\/span>\s*<span class="name">Anonym 1<\/span>/', $page);
    }

    public function test_backend_unchanged_while_feature_off(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(100000);
        $user = $this->user(['first_name' => 'Edi', 'last_name' => 'Entsorger']);
        $this->dispose($user, 1, 2);
        $this->dispose($user, 1, 3);
        Setting::putMany([AppSettings::DISPOSAL_ENABLED => false]);

        $this->actingAs($this->kassier)->get('/admin/benutzer/'.$user->id)->assertOk()
            ->assertSee('data-testid="disposals"', false)->assertSee('Entsorgungen: 2 · 5 Biere');
        $this->get('/admin/lager')->assertOk()
            ->assertSee('data-testid="backend-disposal-ranking"', false)->assertSee('Edi Entsorger')->assertSee('2×');
    }
}

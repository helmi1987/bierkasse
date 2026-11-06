<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\Ledger;
use App\Services\Stats;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Statistics switches in Admin > Einstellungen > Funktionen and "Anonym n" in the ranking (1.29.0).
 */
class StatsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const NAV_LINK = 'href="http://localhost/statistik"';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setDefaultLimit(100000);
    }

    public function test_everything_is_on_by_default(): void
    {
        foreach (AppSettings::STATS_SWITCHES as $key) {
            $this->assertTrue(AppSettings::statsSwitch($key), $key);
        }
        $user = $this->user();
        Ledger::consume($user, 2);

        $this->actingAs($user)->get('/konto')->assertOk()->assertSee(self::NAV_LINK, false)
            ->assertSee(__('account.bookings_stats'))->assertSee(__('account.total_amount'));
        $this->get('/statistik')->assertOk()
            ->assertSee(__('stats.total_beers'))->assertSee('<h2>'.__('stats.course').'</h2>', false)
            ->assertSee('<h2>'.__('stats.per_weekday').'</h2>', false)->assertSee('<h2>'.__('stats.ranking').'</h2>', false)
            ->assertSee('<strong>2</strong>', false)->assertDontSee(__('stats.nothing_enabled'));
    }

    public function test_admin_saves_the_switches_with_audit_entry(): void
    {
        $form = $this->featureForm(['stats_show_ranking' => '0', 'stats_show_amounts' => '0']);
        $this->actingAs($this->user())->put('/admin/einstellungen/funktionen', $form)->assertForbidden();
        $this->actingAs($this->kassier())->put('/admin/einstellungen/funktionen', $form)->assertForbidden();
        $this->assertTrue(AppSettings::statsShows('ranking'));

        $this->actingAs($this->admin())->get('/admin/einstellungen')->assertOk()
            ->assertSee('data-testid="settings-features-stats"', false)->assertSee('name="stats_enabled"', false)
            ->assertSee(__('stats.settings_hint'));
        $this->put('/admin/einstellungen/funktionen', $form)
            ->assertRedirect(route('backend.settings').'#funktionen')->assertSessionHas('status');

        $this->assertFalse(AppSettings::statsShows('ranking'));
        $this->assertFalse(AppSettings::statsShows('amounts'));
        $this->assertTrue(AppSettings::statsEnabled());
        $this->assertTrue(AppSettings::statsShows('ranking_counts'));
        $this->assertTrue(AppSettings::statsDashboardEnabled());

        $new = AuditLog::query()->where('action', 'settings.update')->latest('id')->firstOrFail()->newValues();
        $this->assertFalse($new['stats_show_ranking']);
        $this->assertFalse($new['stats_show_amounts']);
        $this->assertTrue($new['stats_enabled']);

        // A missing checkbox means off (like the other switches).
        $this->put('/admin/einstellungen/funktionen', $this->featureForm(['stats_enabled' => '0']));
        $this->assertFalse(AppSettings::statsEnabled());
        $this->assertTrue(AppSettings::statsShows('ranking'));
    }

    public function test_master_switch_off_hides_page_and_nav_for_everyone(): void
    {
        Setting::put(AppSettings::STATS_ENABLED, false);
        $user = $this->user();
        $admin = $this->admin();

        foreach ([$user, $admin] as $account) {
            $this->actingAs($account)->get('/konto')->assertOk()->assertDontSee(self::NAV_LINK, false);
            $this->get('/statistik')->assertNotFound();
        }
        // The legacy kassier tab still redirects.
        $this->actingAs($this->kassier())->get('/admin/statistik')->assertRedirect(route('stats'));
        // The own statistics on the dashboard have their own switch.
        $this->actingAs($user)->get('/konto')->assertSee(__('account.bookings_stats'));
    }

    public function test_each_part_can_be_hidden(): void
    {
        $user = $this->user();
        Ledger::consume($user, 3);
        $sections = [
            'stats_show_totals' => __('stats.total_beers'),
            'stats_show_months' => '<h2>'.__('stats.course').'</h2>',
            'stats_show_weekdays' => '<h2>'.__('stats.per_weekday').'</h2>',
            'stats_show_ranking' => '<h2>'.__('stats.ranking').'</h2>',
        ];

        foreach ($sections as $key => $marker) {
            foreach (array_keys($sections) as $other) {
                Setting::put($other, $other !== $key);
            }
            $response = $this->actingAs($user)->get('/statistik')->assertOk()->assertDontSee($marker, false);
            foreach ($sections as $otherKey => $otherMarker) {
                if ($otherKey !== $key) {
                    $response->assertSee($otherMarker, false);
                }
            }
            $response->assertDontSee(__('stats.nothing_enabled'));
        }

        // Chart titles are only rendered for shown charts.
        Setting::putMany([AppSettings::STATS_SHOW_MONTHS => false, AppSettings::STATS_SHOW_WEEKDAYS => false]);
        $this->get('/statistik')->assertDontSee('<svg class="chart"', false)
            ->assertDontSee(__('stats.chart_months'))->assertDontSee(__('stats.chart_weekdays'));

        Setting::putMany([AppSettings::STATS_SHOW_TOTALS => false, AppSettings::STATS_SHOW_RANKING => false]);
        $this->get('/statistik')->assertOk()->assertSee(__('stats.nothing_enabled'));
    }

    public function test_ranking_without_counts_shows_only_places(): void
    {
        $user = $this->user(['first_name' => 'Fritz', 'last_name' => 'Fünfer']);
        Ledger::consume($user, 5);
        Setting::putMany([AppSettings::STATS_RANKING_COUNTS => false, AppSettings::STATS_SHOW_TOTALS => false]);

        $this->actingAs($user)->get('/statistik')->assertOk()
            ->assertSee('<span class="rank">1</span>', false)->assertSee('Fritz Fünfer')
            ->assertDontSee('<strong>5</strong>', false);
    }

    public function test_amounts_off_hides_chf_total_on_dashboard(): void
    {
        $this->setPrice(150);
        $user = $this->user();
        Ledger::consume($user, 3);

        $this->actingAs($user)->get('/konto')->assertSee(__('account.total_amount'))->assertSee('CHF 4.50');
        Setting::put(AppSettings::STATS_SHOW_AMOUNTS, false);
        $this->get('/konto')->assertOk()->assertDontSee(__('account.total_amount'))
            ->assertSee(__('account.total_beers'));
    }

    public function test_dashboard_switch_off_shows_only_recent_bookings(): void
    {
        $user = $this->user();
        Ledger::consume($user, 2);
        Setting::put(AppSettings::STATS_DASHBOARD_ENABLED, false);

        $this->actingAs($user)->get('/konto')->assertOk()
            ->assertSee('<h2>'.__('account.bookings').'</h2>', false)->assertDontSee(__('account.bookings_stats'))
            ->assertDontSee(__('account.total_beers'))->assertDontSee(__('account.per_week'))
            ->assertDontSee('<svg class="chart"', false)
            ->assertSee(__('ledger.type.consumption'))->assertSee(__('account.history'));
        // The statistics page itself is not affected.
        $this->get('/statistik')->assertOk()->assertSee(self::NAV_LINK, false);
    }

    public function test_opt_out_users_are_ranked_as_numbered_anonymous(): void
    {
        $visible = $this->user(['first_name' => 'Vreni', 'last_name' => 'Sichtbar']);
        $first = $this->user(['first_name' => 'Heiri', 'last_name' => 'Versteckt', 'stats_opt_out' => true]);
        $second = $this->user(['first_name' => 'Rösli', 'last_name' => 'Geheim', 'stats_opt_out' => true]);
        Ledger::consume($visible, 6);
        Ledger::consume($first, 4);
        Ledger::consume($second, 2);

        $ranking = Stats::global()['ranking'];
        $this->assertSame(['Vreni Sichtbar', 'Anonym 1', 'Anonym 2'], array_column($ranking, 'name'));
        $this->assertSame([false, true, true], array_column($ranking, 'anonymous'));
        $this->assertSame([6, 4, 2], array_column($ranking, 'beers'));

        // Numbering follows the list order, not the user: more beers move Rösli to "Anonym 1".
        Ledger::consume($second, 3);
        $ranking = Stats::global()['ranking'];
        $this->assertSame([$visible->id, $second->id, $first->id], array_column($ranking, 'user_id'));
        $this->assertSame(['Vreni Sichtbar', 'Anonym 1', 'Anonym 2'], array_column($ranking, 'name'));

        $this->actingAs($visible)->get('/statistik')->assertOk()
            ->assertSeeInOrder(['Vreni Sichtbar', 'Anonym 1', 'Anonym 2'])
            ->assertDontSee('Versteckt')->assertDontSee('Geheim')->assertDontSee('Heiri');
        // The opt-out user sees the own (anonymous) row highlighted, still without the name.
        $this->actingAs($first)->get('/statistik')->assertOk()
            ->assertSeeInOrder(['<li class="me rank-3">', '<span class="name">Anonym 2</span>'], false)
            ->assertDontSee('Versteckt');
    }

    public function test_opt_out_stays_effective_with_any_switches(): void
    {
        $hidden = $this->user(['first_name' => 'Heiri', 'last_name' => 'Versteckt', 'stats_opt_out' => true]);
        Ledger::consume($hidden, 1);
        Setting::putMany(array_fill_keys(AppSettings::STATS_SWITCHES, true));
        $this->actingAs($this->user())->get('/statistik')->assertOk()
            ->assertSee('Anonym 1')->assertDontSee('Versteckt')->assertDontSee('Heiri');

        Setting::put(AppSettings::STATS_RANKING_COUNTS, false);
        $this->get('/statistik')->assertOk()->assertSee('Anonym 1')->assertDontSee('Versteckt');
    }
}

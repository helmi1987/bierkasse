<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\BackendStats;
use App\Services\GuestSales;
use App\Services\Ledger;
use App\Support\AppSettings;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Backend statistics /admin/statistik for kassiers and admins (1.31.0).
 */
class BackendStatsTest extends TestCase
{
    use RefreshDatabase;

    private const NOVEMBER = 'zeitraum=monat&von=2026-11-01';

    private User $anna;

    private User $beat;

    private User $hidden;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setDefaultLimit(100000);
        $this->setPrice(200);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function at(string $localTime, callable $booking): mixed
    {
        $this->travelTo(Carbon::parse($localTime, 'Europe/Zurich'));
        $result = $booking();
        $this->travelBack();

        return $result;
    }

    /**
     * November 2026: Anna 2 own beers, a round by Beat (1 for Anna, 1 without a name), 3 guest beers, Anna gives
     * Beat 2 beers (gift), the opt-out user 1, the later "Nur Verwaltung" account 1. October: Beat 2. Price CHF 2.
     */
    private function scenario(): void
    {
        $this->anna = $this->user(['first_name' => 'Anna', 'last_name' => 'Amsel']);
        $this->beat = $this->user(['first_name' => 'Beat', 'last_name' => 'Buchfink']);
        $this->hidden = $this->user(['first_name' => 'Heiri', 'last_name' => 'Versteckt', 'stats_opt_out' => true]);
        $this->manager = $this->kassier(['first_name' => 'Vera', 'last_name' => 'Verwalterin']);

        $this->at('2026-10-15 20:00:00', fn () => Ledger::consume($this->beat, 2));
        $this->at('2026-11-02 18:00:00', fn () => Ledger::consume($this->hidden, 1));
        $this->at('2026-11-02 18:30:00', fn () => Ledger::consume($this->manager, 1));
        $this->manager->forceFill(['admin_only' => true])->save();
        $this->at('2026-11-03 10:00:00', fn () => Ledger::consume($this->anna, 2));
        $this->at('2026-11-04 12:00:00', fn () => Ledger::giveRound($this->beat, [$this->anna->id], 1));
        $this->at('2026-11-05 18:00:00', fn () => GuestSales::book(3, 'cash', null));
        $this->at('2026-11-05 19:00:00', fn () => Ledger::giveBeers($this->anna, [$this->beat->id], 2));

        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));
    }

    private function report(string $query): array
    {
        parse_str($query, $params);

        return BackendStats::report(StatsPeriod::fromQuery($params), BackendStats::filters($params));
    }

    public function test_access_for_kassier_and_admin_only(): void
    {
        $this->get('/admin/statistik')->assertRedirect(route('login'));
        $this->actingAs($this->user())->get('/admin/statistik')->assertForbidden();
        $this->get('/admin/statistik/export')->assertForbidden();

        $this->actingAs($this->kassier())->get('/admin/statistik')->assertOk()
            ->assertSee('<h1>Statistik</h1>', false)
            ->assertSee('href="'.route('backend.stats').'" class="active"', false);
        // The tab is in the kassier nav of the other pages too; no redirect any more.
        $this->get('/admin')->assertOk()->assertSee('href="'.route('backend.stats').'"', false)->assertSeeText('Statistik');
        $this->actingAs($this->admin())->get('/admin/statistik')->assertOk()->assertSee('data-testid="backend-stats-kpis"', false);
    }

    public function test_independent_of_the_user_statistics_switches(): void
    {
        Setting::put(AppSettings::STATS_ENABLED, false);
        foreach (AppSettings::STATS_SWITCHES as $switch) {
            Setting::put($switch, false);
        }
        $kassier = $this->kassier();
        Ledger::consume($kassier, 2);

        $this->actingAs($kassier)->get('/statistik')->assertNotFound();
        $this->get('/admin/statistik')->assertOk()->assertSee('data-testid="kpi-beers"><span>Biere</span><strong>2</strong>', false);
    }

    public function test_types_and_user_filter(): void
    {
        $this->scenario();

        // "alle": own consumption + round beers + guest sales, never the gift.
        $all = $this->report(self::NOVEMBER);
        $this->assertSame(9, $all['beers']);
        $this->assertSame(1800, $all['amount']);
        $this->assertSame(['beers' => 2, 'amount' => 400], ['beers' => $this->report(self::NOVEMBER.'&typ=geschenke')['beers'], 'amount' => $this->report(self::NOVEMBER.'&typ=geschenke')['amount']]);
        $this->assertSame(2, $this->report(self::NOVEMBER.'&typ=runden')['beers']);
        $this->assertSame(400, $this->report(self::NOVEMBER.'&typ=runden')['amount']);
        $this->assertSame(4, $this->report(self::NOVEMBER.'&typ=konsum')['beers']);
        $this->assertSame(3, $this->report(self::NOVEMBER.'&typ=gaeste')['beers']);

        // One user: own beers and beers received in rounds (paid by the giver, at the round's price).
        $this->assertSame(3, $this->report(self::NOVEMBER.'&benutzer='.$this->anna->id)['beers']);
        $this->assertSame(2, $this->report(self::NOVEMBER.'&benutzer='.$this->anna->id.'&typ=geschenke')['beers']);
        $this->assertSame(0, $this->report(self::NOVEMBER.'&benutzer='.$this->beat->id)['beers']);
        $this->assertSame(3, $this->report(self::NOVEMBER.'&benutzer=gaeste')['beers']);
        $this->assertSame(0, $this->report(self::NOVEMBER.'&benutzer=gaeste&typ=konsum')['beers']);
        $this->assertSame(1, $this->report(self::NOVEMBER.'&benutzer=eingeladen')['beers']);

        $this->actingAs($this->kassier());
        $this->get('/admin/statistik?'.self::NOVEMBER)->assertOk()
            ->assertSee('data-testid="kpi-beers"><span>Biere</span><strong>9</strong>', false)
            ->assertSee('CHF 18.00')->assertSee(__('stats.backend.gifts_hint'));
        $this->get('/admin/statistik?'.self::NOVEMBER.'&typ=geschenke')->assertOk()
            ->assertSee('<span>Wert</span><strong>CHF 4.00</strong>', false);
    }

    public function test_grouping_by_time(): void
    {
        $this->scenario();

        $months = $this->report('zeitraum=jahr&von=2026-01-01&raster=monat')['rows'];
        $this->assertCount(12, $months);
        $this->assertSame('Oktober 2026', $months[9]['label']);
        $this->assertSame([2, 9], [$months[9]['beers'], $months[10]['beers']]);

        $weeks = $this->report(self::NOVEMBER.'&raster=woche')['rows'];
        $this->assertSame(['KW 44', 'KW 45', 'KW 46', 'KW 47', 'KW 48', 'KW 49'], array_column($weeks, 'short'));
        $this->assertSame(9, $weeks[1]['beers']);

        // Default raster of a month: days.
        $this->assertCount(30, $this->report(self::NOVEMBER)['rows']);

        $this->actingAs($this->kassier())->get('/admin/statistik?'.self::NOVEMBER.'&raster=woche')->assertOk()
            ->assertSee('KW 45 (2.–8.11.2026)')->assertSee('<svg class="chart"', false)
            ->assertSee('data-testid="stats-total-row"', false)->assertSee('18.00')
            // The period bar keeps the filters.
            ->assertSee('raster=woche', false);
        // Daily raster over a long period: no chart.
        $this->get('/admin/statistik?zeitraum=jahr&von=2026-01-01&raster=tag')->assertOk()
            ->assertDontSee('<svg class="chart"', false)->assertSee(__('stats.backend.too_many_for_chart', ['max' => 62]));
    }

    public function test_grouping_by_user_with_ranks_and_anonymous(): void
    {
        $this->scenario();

        $rows = $this->report(self::NOVEMBER.'&gruppe=benutzer')['rows'];
        $byName = array_column($rows, null, 'name');
        $this->assertSame(['Gäste', 'Anna Amsel', 'Gäste (eingeladen)', 'Anonym 1', 'Ohne Bierkonto'], array_column($rows, 'name'));
        $this->assertSame([1, 1, 3, 3, 3], array_column($rows, 'rank'));
        $this->assertSame(3, $byName['Anna Amsel']['beers']);
        $this->assertSame(1, $byName['Ohne Bierkonto']['beers']);

        $this->actingAs($this->kassier())->get('/admin/statistik?'.self::NOVEMBER.'&gruppe=benutzer')->assertOk()
            ->assertSee('Anonym 1')->assertSee('Ohne Bierkonto')->assertDontSee('Versteckt')
            ->assertSee('<td colspan="2">Total</td>', false)->assertSee('18.00');
        // The custom period form carries the other filters as hidden fields.
        $this->get('/admin/statistik?zeitraum=frei&gruppe=benutzer&typ=runden')->assertOk()
            ->assertSee('<input type="hidden" name="gruppe" value="benutzer">', false)
            ->assertSee('<input type="hidden" name="typ" value="runden">', false);
    }

    public function test_comparison_with_the_preceding_period(): void
    {
        $user = $this->user();
        $this->at('2026-10-10 20:00:00', fn () => Ledger::consume($user, 2));
        $this->at('2026-11-03 20:00:00', fn () => Ledger::consume($user, 4));
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));

        $report = $this->report(self::NOVEMBER);
        $this->assertSame(2, $report['previous']['beers']);
        $this->assertSame(100.0, $report['previous']['change_beers']);
        // Custom: the same number of days before "von".
        $this->assertSame(2, $this->report('zeitraum=frei&von=2026-11-01&bis=2026-11-30')['previous']['beers']);
        $this->assertNull($this->report('')['previous']);

        $this->actingAs($this->kassier());
        $this->get('/admin/statistik?'.self::NOVEMBER)->assertOk()
            ->assertSee('data-testid="kpi-change"><span>Veränderung</span><strong>+100 %</strong>', false);
        $this->get('/admin/statistik')->assertOk()
            ->assertSee('data-testid="kpi-change"><span>Veränderung</span><strong>–</strong>', false);
    }

    public function test_quarter_period(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));
        $period = StatsPeriod::fromQuery(['zeitraum' => 'quartal', 'von' => '2026-11-06']);
        $this->assertSame('Q4 2026', $period->label());
        $this->assertSame(['2026-10-01 00:00:00', '2026-12-31 23:59:59'], $period->dbBounds());
        $this->assertSame(['Okt', 'Nov', 'Dez'], array_values($period->buckets()));
        $this->assertNull($period->next());

        $this->actingAs($this->kassier())->get('/admin/statistik?zeitraum=quartal&von=2026-11-06&typ=runden')->assertOk()
            ->assertSee('<h2 class="period-label" data-testid="period-label">Q4 2026</h2>', false)
            ->assertSee('href="'.e(route('backend.stats', ['zeitraum' => 'quartal', 'von' => '2026-07-01', 'typ' => 'runden'])).'"', false)
            ->assertDontSee('data-testid="period-next"', false);
        // The user page has the quarter too.
        $this->actingAs($this->user())->get('/statistik?zeitraum=quartal&von=2026-11-06')->assertOk()->assertSee('Q4 2026');
    }

    public function test_user_select_hides_opt_out_users(): void
    {
        $this->scenario();

        // Livewire page (1.36.0): a morph marker of the @unless follows the name inside the option.
        $response = $this->actingAs($this->kassier())->get('/admin/statistik')->assertOk()
            ->assertSee('<option value="'.$this->anna->id.'" >Anna Amsel<', false)
            ->assertDontSee('Versteckt')->assertDontSee('Verwalterin')
            ->assertSee(__('stats.backend.anonymous_hint'));
        $this->assertStringNotContainsString('value="'.$this->hidden->id.'"', (string) $response->getContent());

        // Selecting the opt-out user by id falls back to everybody.
        $this->assertSame('alle', BackendStats::filters(['benutzer' => (string) $this->hidden->id])['benutzer']);
        // After releasing the name the user can be chosen.
        $this->hidden->forceFill(['stats_opt_out' => false])->save();
        $this->get('/admin/statistik')->assertSee('Heiri Versteckt');
    }

    public function test_csv_export(): void
    {
        $this->scenario();

        $this->actingAs($this->user())->get('/admin/statistik/export?'.self::NOVEMBER)->assertForbidden();
        $response = $this->actingAs($this->kassier())->get('/admin/statistik/export?'.self::NOVEMBER.'&gruppe=benutzer')->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=statistik-2026-11-06-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $lines = explode("\n", trim($response->streamedContent()));
        $this->assertSame('rang,name,biere,chf', $lines[0]);
        $this->assertContains('1,"Anna Amsel",3,6.00', $lines);
        $this->assertContains('3,"Anonym 1",1,2.00', $lines);
        $this->assertSame(',Total,9,18.00', end($lines));
        $this->assertStringNotContainsString('Versteckt', implode("\n", $lines));

        $lines = explode("\n", trim($this->get('/admin/statistik/export?'.self::NOVEMBER.'&raster=woche')->streamedContent()));
        $this->assertSame('zeitraum,biere,chf', $lines[0]);
        $this->assertSame('"KW 45 (2.–8.11.2026)",9,18.00', $lines[2]);
        $this->assertSame('Total,9,18.00', end($lines));
    }

    public function test_invalid_filters_fall_back_to_defaults(): void
    {
        $this->assertSame(BackendStats::DEFAULTS, BackendStats::filters(['benutzer' => '999999', 'typ' => 'x', 'gruppe' => ['a'], 'raster' => 'stunde']));
        $this->actingAs($this->kassier());
        $this->get('/admin/statistik?zeitraum=nope&von=2026-02-31&benutzer=0&typ[]=1&gruppe=x&raster=y')->assertOk();
        $this->get('/admin/statistik/export?benutzer=-1&typ=%00')->assertOk();
    }
}

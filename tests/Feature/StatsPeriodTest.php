<?php

namespace Tests\Feature;

use App\Models\GuestSale;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Ledger;
use App\Services\Stats;
use App\Support\AppSettings;
use App\Support\StatsPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Period filter on /statistik, comparison KPIs and the dashboard link (1.30.0).
 */
class StatsPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setDefaultLimit(100000);
    }

    private function consumeAt(string $localTime, int $beers, $user = null): Transaction
    {
        $this->travelTo(Carbon::parse($localTime, 'Europe/Zurich'));
        $tx = Ledger::consume($user ?? $this->user(), $beers);
        $this->travelBack();

        return $tx;
    }

    private function rawCreatedAt(Transaction $tx): string
    {
        return (string) DB::table('transactions')->where('id', $tx->id)->value('created_at');
    }

    public function test_month_boundary_follows_the_app_time_zone(): void
    {
        $this->assertSame('Europe/Zurich', config('app.timezone'));
        $user = $this->user();
        $october = $this->consumeAt('2026-10-31 23:30:00', 2, $user);
        $november = $this->consumeAt('2026-11-01 00:30:00', 3, $user);
        // Eloquent writes the wall-clock time of the app time zone (UTC would be 22:30 and 23:30 on 31.10.).
        $this->assertSame('2026-10-31 23:30:00', $this->rawCreatedAt($october));
        $this->assertSame('2026-11-01 00:30:00', $this->rawCreatedAt($november));
        $this->assertSame('2026-10-31T22:30:00+00:00', $october->created_at->copy()->utc()->toIso8601String());

        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'Europe/Zurich'));
        $this->assertSame(3, Stats::global(period: StatsPeriod::fromQuery(['zeitraum' => 'monat', 'von' => '2026-11-15']))['total_beers']);
        $this->assertSame(2, Stats::global(period: StatsPeriod::fromQuery(['zeitraum' => 'monat', 'von' => '2026-10-01']))['total_beers']);
        $this->assertSame(['month' => 3, 'previous_month' => 2, 'change' => 50.0, 'year' => 5, 'previous_year' => 0], Stats::comparison());

        $response = $this->actingAs($user)->get('/statistik?zeitraum=monat&von=2026-11-15')->assertOk()
            ->assertSee('<h2 class="period-label" data-testid="period-label">November 2026</h2>', false)
            ->assertSee('<strong>3</strong>', false)
            ->assertSee('+50 %')
            ->assertSee(__('stats.compare'))
            ->assertSee(route('stats', ['zeitraum' => 'monat', 'von' => '2026-10-01']))
            ->assertDontSee('data-testid="period-next"', false);
        $this->assertStringContainsString('data-testid="compare-previous-month"><span>'.__('stats.previous_month').'</span><strong>2</strong>', $response->getContent());
        // Day labels 1…30 of November.
        $response->assertSee('class="chart-label"', false)->assertSee('>1</text>', false)
            ->assertSee('>30</text>', false)->assertDontSee('>31</text>', false);
    }

    public function test_week_view(): void
    {
        $user = $this->user();
        $this->consumeAt('2026-11-03 20:00:00', 4, $user); // Tuesday of KW 45
        $this->consumeAt('2026-10-28 20:00:00', 1, $user); // KW 44
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));

        $stats = Stats::global(period: StatsPeriod::fromQuery(['zeitraum' => 'woche']));
        $this->assertSame(4, $stats['total_beers']);
        $this->assertSame(['Mo 2.11.', 'Di 3.11.', 'Mi 4.11.', 'Do 5.11.', 'Fr 6.11.', 'Sa 7.11.', 'So 8.11.'], array_column($stats['course'], 'label'));
        $this->assertSame([0, 4, 0, 0, 0, 0, 0], array_column($stats['course'], 'beers'));

        // Current week: previous link, no next link.
        $this->actingAs($user)->get('/statistik?zeitraum=woche')->assertOk()
            ->assertSee('KW 45 (2.–8.11.2026)')
            ->assertSee('Mo 2.11.')->assertSee('So 8.11.')
            ->assertSee(route('stats', ['zeitraum' => 'woche', 'von' => '2026-10-26']))
            ->assertDontSee('data-testid="period-next"', false)
            ->assertSee('class="btn small primary" href="'.e(route('stats', ['zeitraum' => 'woche'])).'"', false);

        // Any day of the week selects the week; months in the range when the week spans two.
        $this->get('/statistik?zeitraum=woche&von=2026-10-29')->assertOk()
            ->assertSee('KW 44 (26.10.–1.11.2026)')
            ->assertSee(route('stats', ['zeitraum' => 'woche', 'von' => '2026-10-19']))
            ->assertSee(route('stats', ['zeitraum' => 'woche', 'von' => '2026-11-02']))
            ->assertSee('data-testid="period-next"', false);
        $this->assertSame(1, Stats::global(period: StatsPeriod::fromQuery(['zeitraum' => 'woche', 'von' => '2026-10-29']))['total_beers']);
        $this->assertSame('KW 53 (28.12.2026–3.1.2027)', StatsPeriod::fromQuery(['zeitraum' => 'woche', 'von' => '2027-01-01'])->label());
    }

    public function test_year_view(): void
    {
        $user = $this->user();
        $this->consumeAt('2025-03-10 12:00:00', 2, $user);
        $this->consumeAt('2026-02-10 12:00:00', 5, $user);
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));

        $stats = Stats::global(period: StatsPeriod::fromQuery(['zeitraum' => 'jahr', 'von' => '2025-07-01']));
        $this->assertSame(2, $stats['total_beers']);
        $this->assertSame(array_values(__('stats.month_short')), array_column($stats['course'], 'label'));
        $this->assertSame(2, $stats['course'][2]['beers']);

        $this->actingAs($user)->get('/statistik?zeitraum=jahr&von=2025-07-01')->assertOk()
            ->assertSee('<h2 class="period-label" data-testid="period-label">2025</h2>', false)
            ->assertSee(route('stats', ['zeitraum' => 'jahr', 'von' => '2024-01-01']))
            ->assertSee(route('stats', ['zeitraum' => 'jahr', 'von' => '2026-01-01']))
            ->assertSee(__('stats.chart_course_months', ['period' => '2025']));
        $this->get('/statistik?zeitraum=jahr')->assertOk()
            ->assertSee('data-testid="period-label">2026</h2>', false)->assertDontSee('data-testid="period-next"', false);
    }

    public function test_custom_period(): void
    {
        $user = $this->user();
        $this->consumeAt('2026-09-15 12:00:00', 2, $user);
        $this->consumeAt('2026-11-02 12:00:00', 3, $user);
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));

        $period = StatsPeriod::fromQuery(['zeitraum' => 'frei', 'von' => '2026-11-02', 'bis' => '2026-11-06']);
        $this->assertSame('2.11.2026 – 6.11.2026', $period->label());
        $this->assertSame(3, Stats::global(period: $period)['total_beers']);
        // Swapped dates.
        $swapped = StatsPeriod::fromQuery(['zeitraum' => 'frei', 'von' => '2026-11-06', 'bis' => '2026-11-02']);
        $this->assertSame('2.11.2026 – 6.11.2026', $swapped->label());
        // Invalid dates fall back to the default (last 30 days ending today).
        foreach (['2026-02-31', 'gestern', '6.11.2026', ''] as $bad) {
            $this->assertSame('8.10.2026 – 6.11.2026', StatsPeriod::fromQuery(['zeitraum' => 'frei', 'von' => $bad, 'bis' => $bad])->label(), $bad);
        }
        $this->assertSame('8.10.2026 – 6.11.2026', StatsPeriod::fromQuery(['zeitraum' => 'frei', 'von' => ['x']])->label());
        // At most 5 years.
        $this->assertSame('7.11.2021 – 6.11.2026', StatsPeriod::fromQuery(['zeitraum' => 'frei', 'von' => '2001-01-01', 'bis' => '2026-11-06'])->label());

        $this->actingAs($user)->get('/statistik?zeitraum=frei&von=2026-11-06&bis=2026-11-02')->assertOk()
            ->assertSee('2.11.2026 – 6.11.2026')
            ->assertSee('<input type="date" id="period-from" name="von" value="2026-11-02">', false)
            ->assertSee('<input type="hidden" name="zeitraum" value="frei">', false)
            ->assertSee('<strong>3</strong>', false)
            ->assertSee('>2.11.</text>', false)
            ->assertDontSee('data-testid="period-prev"', false);
        $this->get('/statistik?zeitraum=frei&von=kaputt')->assertOk()->assertSee('8.10.2026 – 6.11.2026');

        // Up to 62 days per day, longer per month.
        $this->assertSame('day', StatsPeriod::fromQuery(['zeitraum' => 'frei', 'von' => '2026-09-06', 'bis' => '2026-11-06'])->granularity());
        $long = StatsPeriod::fromQuery(['zeitraum' => 'frei', 'von' => '2026-09-01', 'bis' => '2026-11-06']);
        $this->assertSame('month', $long->granularity());
        $stats = Stats::global(period: $long);
        $this->assertSame(['Sep 26', 'Okt 26', 'Nov 26'], array_column($stats['course'], 'label'));
        $this->assertSame([2, 0, 3], array_column($stats['course'], 'beers'));
        $this->get('/statistik?zeitraum=frei&von=2026-09-01&bis=2026-11-06')->assertOk()->assertSee('>Okt 26</text>', false);
    }

    public function test_ranking_follows_the_period(): void
    {
        $inside = $this->user(['first_name' => 'Vreni', 'last_name' => 'Drin']);
        $outside = $this->user(['first_name' => 'Ruedi', 'last_name' => 'Draussen']);
        $hidden = $this->user(['first_name' => 'Heiri', 'last_name' => 'Versteckt', 'stats_opt_out' => true]);
        $this->consumeAt('2026-11-03 18:00:00', 2, $inside);
        $this->consumeAt('2026-11-04 18:00:00', 3, $hidden);
        $this->consumeAt('2026-10-20 18:00:00', 9, $outside);
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));

        $ranking = Stats::global(period: StatsPeriod::fromQuery(['zeitraum' => 'monat']))['ranking'];
        $this->assertSame([
            ['rank' => 1, 'user_id' => $hidden->id, 'name' => 'Anonym 1', 'beers' => 3, 'anonymous' => true],
            ['rank' => 2, 'user_id' => $inside->id, 'name' => 'Vreni Drin', 'beers' => 2, 'anonymous' => false],
        ], $ranking);

        $this->actingAs($inside)->get('/statistik?zeitraum=monat')->assertOk()
            ->assertSee('Vreni Drin')->assertSee('Anonym 1')->assertDontSee('Ruedi Draussen')->assertDontSee('Versteckt');
        $this->get('/statistik')->assertOk()->assertSee('Ruedi Draussen');
    }

    public function test_rounds_and_guest_sales_follow_the_period(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'Europe/Zurich'));
        $round = Transaction::query()->create([
            'user_id' => $this->user()->id, 'type' => Transaction::TYPE_ROUND, 'status' => Transaction::STATUS_CONFIRMED,
            'quantity' => 4, 'unit_price' => 150, 'amount' => -600, 'created_by' => null,
        ]);
        $anna = $this->user();
        $round->roundItems()->create(['user_id' => null, 'quantity' => 3]);
        $round->roundItems()->create(['user_id' => $anna->id, 'quantity' => 1]);
        $this->travelTo(Carbon::parse('2026-11-04 12:00:00', 'Europe/Zurich'));
        GuestSale::query()->create(['beers' => 2, 'unit_price' => 150, 'amount' => 300, 'method' => 'cash']);
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));

        $november = Stats::global(period: StatsPeriod::fromQuery(['zeitraum' => 'monat']));
        $this->assertSame(2, $november['total_beers']);
        $this->assertSame([Stats::GUESTS_ID], array_column($november['ranking'], 'user_id'));
        $october = Stats::global(period: StatsPeriod::fromQuery(['zeitraum' => 'monat', 'von' => '2026-10-01']));
        // Only the round's items count (4), never the round transaction itself.
        $this->assertSame(4, $october['total_beers']);
        $this->assertSame([Stats::INVITED_ID, $anna->id], array_column($october['ranking'], 'user_id'));
        $this->assertSame(['month' => 2, 'previous_month' => 4, 'change' => -50.0, 'year' => 6, 'previous_year' => 0], Stats::comparison());
        // Beers received in a round count for the drinker.
        $this->assertSame(['month' => 0, 'previous_month' => 1, 'change' => -100.0, 'year' => 1, 'previous_year' => 0], Stats::comparison($anna));
        $this->assertSame(0, Stats::comparison($round->user)['year']);
        $this->actingAs($anna)->get('/statistik')->assertOk()->assertSee('−50 %');
    }

    public function test_gesamt_is_unchanged(): void
    {
        $user = $this->user();
        $this->consumeAt('2024-05-01 12:00:00', 7, $user);
        $this->consumeAt('2026-11-02 12:00:00', 1, $user);
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));

        foreach ([[], ['zeitraum' => 'gesamt'], ['zeitraum' => 'quatsch', 'von' => '2026-01-01']] as $query) {
            $period = StatsPeriod::fromQuery($query);
            $this->assertTrue($period->isAll());
            $stats = Stats::global(period: $period);
            $this->assertSame(8, $stats['total_beers']);
            $this->assertCount(Stats::MONTHS, $stats['course']);
            $this->assertSame('Dez 25', $stats['course'][0]['label']);
            $this->assertSame('Nov 26', $stats['course'][11]['label']);
        }

        $this->actingAs($user)->get('/statistik?zeitraum=quatsch')->assertOk()
            ->assertSee('<strong>8</strong>', false)
            ->assertSee('<h2>'.__('stats.course').'</h2>', false)
            ->assertSee(__('stats.chart_months'))
            ->assertSee('data-testid="period-label">'.__('stats.period.gesamt').'</h2>', false)
            ->assertDontSee('data-testid="period-prev"', false)->assertDontSee('data-testid="period-next"', false);
    }

    public function test_comparison_on_the_dashboard_is_personal(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->consumeAt('2026-11-02 12:00:00', 2, $me);
        $this->consumeAt('2026-11-03 12:00:00', 6, $other);
        $this->consumeAt('2025-06-03 12:00:00', 1, $me);
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));

        $this->assertSame(['month' => 2, 'previous_month' => 0, 'change' => null, 'year' => 2, 'previous_year' => 1], Stats::comparison($me));
        $this->assertSame(['month' => 8, 'previous_month' => 0, 'change' => null, 'year' => 8, 'previous_year' => 1], Stats::comparison());

        $page = $this->actingAs($me)->get('/konto')->assertOk()
            ->assertSee('data-testid="stats-compare"', false)
            ->assertSee('data-testid="stats-link"', false)->assertSee(__('account.to_stats'))
            ->assertDontSee('data-testid="period-bar"', false)->getContent();
        $this->assertStringContainsString('data-testid="compare-month"><span>'.__('stats.this_month').'</span><strong>2</strong>', $page);
        $this->assertStringContainsString('data-testid="compare-change"><span>'.__('stats.change').'</span><strong>–</strong>', $page);
        $this->assertStringContainsString('data-testid="compare-previous-year"><span>'.__('stats.previous_year').'</span><strong>1</strong>', $page);

        $global = $this->get('/statistik')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="compare-month"><span>'.__('stats.this_month').'</span><strong>8</strong>', $global);
        $this->assertStringContainsString('data-testid="compare-change"><span>'.__('stats.change').'</span><strong>–</strong>', $global);

        // No link when the statistics page is off; no comparison when the own statistics are off.
        Setting::put(AppSettings::STATS_ENABLED, false);
        $this->get('/konto')->assertOk()->assertSee('data-testid="stats-compare"', false)->assertDontSee('data-testid="stats-link"', false);
        Setting::put(AppSettings::STATS_DASHBOARD_ENABLED, false);
        $this->get('/konto')->assertOk()->assertDontSee('data-testid="stats-compare"', false);
    }

    public function test_switches(): void
    {
        $user = $this->user();
        $this->consumeAt('2026-11-02 12:00:00', 2, $user);
        $this->travelTo(Carbon::parse('2026-11-06 12:00:00', 'Europe/Zurich'));

        Setting::put(AppSettings::STATS_SHOW_TOTALS, false);
        $this->actingAs($user)->get('/statistik?zeitraum=woche')->assertOk()
            ->assertDontSee('data-testid="stats-total"', false)->assertDontSee('data-testid="stats-compare"', false)
            ->assertDontSee(__('stats.compare'))->assertSee('data-testid="period-bar"', false);

        Setting::put(AppSettings::STATS_ENABLED, false);
        foreach (['', '?zeitraum=woche', '?zeitraum=frei&von=2026-01-01&bis=2026-02-01', '?zeitraum=quatsch'] as $query) {
            $this->get('/statistik'.$query)->assertNotFound();
        }
    }
}

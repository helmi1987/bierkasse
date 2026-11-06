<?php

namespace App\Http\Controllers;

use App\Services\Stats;
use App\Support\AppSettings;
use App\Support\BarChart;
use App\Support\StatsPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Statistics for all logged-in users. Users with stats_opt_out are not named ("Anonym n").
 * Master switch and shown parts: Admin > Einstellungen > Funktionen (AppSettings::STATS_SWITCHES).
 * Period via GET (1.30.0): ?zeitraum=gesamt|woche|monat|quartal|jahr|frei&von=YYYY-MM-DD&bis=YYYY-MM-DD (StatsPeriod).
 */
class StatsController extends Controller
{
    public function index(Request $request): View
    {
        // Off for everyone, admins included; kassiers and admins keep the backend page Kassier > Statistik (1.31.0).
        abort_unless(AppSettings::statsEnabled(), 404);

        return view('stats.index', self::data(StatsPeriod::fromQuery($request->query())));
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(?StatsPeriod $period = null): array
    {
        $period ??= StatsPeriod::all();
        $stats = Stats::global(period: $period);
        $show = array_map(fn (string $part) => AppSettings::statsShows($part), array_combine(array_keys(AppSettings::STATS_PARTS), array_keys(AppSettings::STATS_PARTS)));

        return [
            'stats' => $stats,
            'show' => $show,
            'period' => $period,
            // Fixed ranges (this/previous month and year), independent of the selected period.
            'comparison' => $show['totals'] ? Stats::comparison() : null,
            // Only the charts that are shown are rendered.
            'courseChart' => $show['months'] ? BarChart::render(array_column($stats['course'], 'label'), array_column($stats['course'], 'beers'), $period->chartTitle()) : null,
            'weekdayChart' => $show['weekdays'] ? BarChart::render(array_column($stats['weekdays'], 'label'), array_column($stats['weekdays'], 'beers'), __('stats.chart_weekdays')) : null,
            'nothing' => ! ($show['totals'] || $show['months'] || $show['weekdays'] || $show['ranking']),
        ];
    }
}

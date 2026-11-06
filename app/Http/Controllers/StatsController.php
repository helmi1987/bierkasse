<?php

namespace App\Http\Controllers;

use App\Services\Stats;
use App\Support\AppSettings;
use App\Support\BarChart;
use Illuminate\View\View;

/**
 * Statistics for all logged-in users. Users with stats_opt_out are not named ("Anonym n").
 * Master switch and shown parts: Admin > Einstellungen > Funktionen (AppSettings::STATS_SWITCHES).
 */
class StatsController extends Controller
{
    public function index(): View
    {
        // Off for everyone, admins included (a backend statistics page follows later).
        abort_unless(AppSettings::statsEnabled(), 404);

        return view('stats.index', self::data());
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        $stats = Stats::global();
        $show = array_map(fn (string $part) => AppSettings::statsShows($part), array_combine(array_keys(AppSettings::STATS_PARTS), array_keys(AppSettings::STATS_PARTS)));

        return [
            'stats' => $stats,
            'show' => $show,
            // Only the charts that are shown are rendered.
            'monthChart' => $show['months'] ? BarChart::render(array_column($stats['months'], 'label'), array_column($stats['months'], 'beers'), __('stats.chart_months')) : null,
            'weekdayChart' => $show['weekdays'] ? BarChart::render(array_column($stats['weekdays'], 'label'), array_column($stats['weekdays'], 'beers'), __('stats.chart_weekdays')) : null,
            'nothing' => ! ($show['totals'] || $show['months'] || $show['weekdays'] || $show['ranking']),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\Stats;
use App\Support\BarChart;
use Illuminate\View\View;

/**
 * Statistics for all logged-in users. Users with stats_opt_out are not named.
 */
class StatsController extends Controller
{
    public function index(): View
    {
        return view('stats.index', self::data());
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        $stats = Stats::global();

        return [
            'stats' => $stats,
            'monthChart' => BarChart::render(array_column($stats['months'], 'label'), array_column($stats['months'], 'beers'), __('stats.chart_months')),
            'weekdayChart' => BarChart::render(array_column($stats['weekdays'], 'label'), array_column($stats['weekdays'], 'beers'), __('stats.chart_weekdays')),
        ];
    }
}

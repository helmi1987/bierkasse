<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\BackendStats;
use App\Support\BarChart;
use App\Support\Csv;
use App\Support\Money;
use App\Support\StatsPeriod;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backend statistics "Statistik" (kassiers and admins, read-only, 1.31.0), independent of the user statistics
 * switches. GET: zeitraum/von/bis (StatsPeriod), benutzer, typ, gruppe, raster (BackendStats::filters()).
 * The page is the Livewire component App\Livewire\Backend\Stats (1.36.0). The CSV export takes the same
 * parameters and contains the same rows as the table.
 */
class StatsController extends Controller
{
    /** Host page of the Livewire component App\Livewire\Backend\Stats (1.36.0, filters from the query). */
    public function index(): View
    {
        return view('backend.stats');
    }

    /**
     * View data of the page for the GET parameters (zeitraum/von/bis, benutzer, typ, gruppe, raster); used by
     * App\Livewire\Backend\Stats.
     */
    public static function data(array $query): array
    {
        $period = StatsPeriod::fromQuery($query);
        $filters = BackendStats::filters($query);
        $report = BackendStats::report($period, $filters);
        $byTime = $filters['gruppe'] === BackendStats::GROUP_TIME;
        $chart = $byTime && $report['rows'] !== [] && count($report['rows']) <= BackendStats::CHART_MAX_ROWS
            ? BarChart::render(array_column($report['rows'], 'short'), array_column($report['rows'], 'beers'), __('stats.backend.chart_title', ['period' => $period->label()]))
            : null;
        $filterQuery = BackendStats::query($filters);

        return [
            'period' => $period,
            'filters' => $filters,
            'report' => $report,
            'byTime' => $byTime,
            'chart' => $chart,
            'users' => BackendStats::selectableUsers(),
            'filterQuery' => $filterQuery,
            'exportUrl' => route('backend.stats.export', $period->query() + $filterQuery),
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $period = StatsPeriod::fromQuery($request->query());
        $filters = BackendStats::filters($request->query());
        $report = BackendStats::report($period, $filters);
        $byTime = $filters['gruppe'] === BackendStats::GROUP_TIME;

        return response()->streamDownload(function () use ($report, $byTime) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $byTime ? ['zeitraum', 'biere', 'chf'] : ['rang', 'name', 'biere', 'chf'], escape: '');
            foreach ($report['rows'] as $row) {
                $values = [$row['beers'], Money::plain($row['amount'])];
                fputcsv($out, $byTime ? [Csv::safe($row['label']), ...$values] : [$row['rank'], Csv::safe($row['name']), ...$values], escape: '');
            }
            $total = [$report['table_beers'], Money::plain($report['table_amount'])];
            fputcsv($out, $byTime ? ['Total', ...$total] : ['', 'Total', ...$total], escape: '');
            fclose($out);
        }, 'statistik-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}

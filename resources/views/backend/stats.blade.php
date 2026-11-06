{{--
    Backend statistics (1.31.0, kassiers and admins): filters, KPIs with the preceding period, table by time or by
    drinker, chart and CSV export. Plain GET links and forms (no JS). See App\Http\Controllers\Backend\StatsController.
--}}
@extends('layouts.app')

@section('title', __('stats.backend.title'))

@section('content')
@php
    $percent = function (?float $change): string {
        $change = $change === null ? null : round($change);

        return match (true) {
            $change === null => '–',
            $change > 0 => '+'.number_format($change, 0, '.', "'").' %',
            $change < 0 => '−'.number_format(abs($change), 0, '.', "'").' %',
            default => '0 %',
        };
    };
    $gifts = $filters['typ'] === \App\Services\BackendStats::TYPE_GIFTS;
    $amountLabel = $gifts ? __('stats.backend.gift_amount') : __('stats.backend.amount');
@endphp
@include('partials.backend-nav')
<h1>{{ __('stats.backend.title') }}</h1>
<p class="muted">{{ __('stats.backend.intro') }}</p>

@include('stats._period', ['period' => $period, 'periodRoute' => 'backend.stats', 'extraQuery' => $filterQuery])

<section class="card">
    <form method="get" action="{{ route('backend.stats') }}" class="inline-form stats-filters" data-testid="stats-filters">
        @foreach ($period->query() as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <label for="f-user">{{ __('stats.backend.user') }}</label>
        <select id="f-user" name="benutzer">
            @foreach (['alle', 'gaeste', 'eingeladen'] as $option)
                <option value="{{ $option }}" @selected($filters['benutzer'] === $option)>{{ __('stats.backend.user_option.'.$option) }}</option>
            @endforeach
            @if ($users->isNotEmpty())
                <optgroup label="{{ __('stats.backend.users_group') }}">
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}" @selected($filters['benutzer'] === (string) $u->id)>{{ $u->displayName() }}@unless ($u->isActive()) {{ __('stats.backend.inactive') }}@endunless</option>
                    @endforeach
                </optgroup>
            @endif
        </select>
        <label for="f-type">{{ __('stats.backend.type') }}</label>
        <select id="f-type" name="typ">
            @foreach (\App\Services\BackendStats::TYPES as $option)
                <option value="{{ $option }}" @selected($filters['typ'] === $option)>{{ __('stats.backend.type_option.'.$option) }}</option>
            @endforeach
        </select>
        <label for="f-group">{{ __('stats.backend.grouping') }}</label>
        <select id="f-group" name="gruppe">
            @foreach (\App\Services\BackendStats::GROUPS as $option)
                <option value="{{ $option }}" @selected($filters['gruppe'] === $option)>{{ __('stats.backend.group_option.'.$option) }}</option>
            @endforeach
        </select>
        <label for="f-raster">{{ __('stats.backend.raster') }}</label>
        <select id="f-raster" name="raster">
            @foreach (array_keys(\App\Services\BackendStats::RASTERS) as $option)
                <option value="{{ $option }}" @selected($filters['raster'] === $option)>{{ __('stats.backend.raster_option.'.$option) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn small primary">{{ __('stats.backend.submit') }}</button>
    </form>
    <p class="hint">{{ __('stats.backend.anonymous_hint') }}</p>
</section>

<div class="kpis" data-testid="backend-stats-kpis">
    <div class="kpi" data-testid="kpi-beers"><span>{{ __('stats.backend.beers') }}</span><strong>{{ $report['beers'] }}</strong><small>{{ $period->label() }}</small></div>
    <div class="kpi" data-testid="kpi-amount"><span>{{ $amountLabel }}</span><strong>{{ chf($report['amount']) }}</strong></div>
    @if ($report['previous'])
        <div class="kpi" data-testid="kpi-previous"><span>{{ __('stats.backend.previous') }}</span><strong>{{ $report['previous']['beers'] }}</strong><small>{{ chf($report['previous']['amount']) }} · {{ $report['previous']['label'] }}</small></div>
        <div class="kpi" data-testid="kpi-change"><span>{{ __('stats.backend.change') }}</span><strong>{{ $percent($report['previous']['change_beers']) }}</strong><small>{{ __('stats.backend.col_chf') }} {{ $percent($report['previous']['change_amount']) }}</small></div>
    @else
        <div class="kpi" data-testid="kpi-previous"><span>{{ __('stats.backend.previous') }}</span><strong>–</strong><small>{{ __('stats.backend.no_comparison') }}</small></div>
        <div class="kpi" data-testid="kpi-change"><span>{{ __('stats.backend.change') }}</span><strong>–</strong></div>
    @endif
</div>

<section class="card">
    <div class="row-buttons">
        <a class="btn small" href="{{ $exportUrl }}" data-testid="stats-export">{{ __('stats.backend.export') }}</a>
    </div>
    @if ($report['rows'] === [])
        <p class="muted">{{ __('stats.backend.empty') }}</p>
    @else
        <div class="table-wrap">
            <table class="table" data-testid="backend-stats-table">
                <thead>
                    <tr>
                        @if ($byTime)
                            <th>{{ __('stats.backend.col_period') }}</th>
                        @else
                            <th class="num">{{ __('stats.backend.col_rank') }}</th>
                            <th>{{ __('stats.backend.col_name') }}</th>
                        @endif
                        <th class="num">{{ __('stats.backend.col_beers') }}</th>
                        <th class="num">{{ __('stats.backend.col_chf') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['rows'] as $row)
                        <tr>
                            @if ($byTime)
                                <td>{{ $row['label'] }}</td>
                            @else
                                <td class="num">{{ $row['rank'] }}</td>
                                <td>{{ $row['name'] }}</td>
                            @endif
                            <td class="num">{{ $row['beers'] }}</td>
                            <td class="num">{{ \App\Support\Money::plain($row['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr data-testid="stats-total-row">
                        <td colspan="{{ $byTime ? 1 : 2 }}">{{ __('stats.backend.total') }}</td>
                        <td class="num">{{ $report['table_beers'] }}</td>
                        <td class="num">{{ \App\Support\Money::plain($report['table_amount']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @if ($byTime)
            @if ($chart)
                {!! $chart !!}
            @else
                <p class="hint">{{ __('stats.backend.too_many_for_chart', ['max' => \App\Services\BackendStats::CHART_MAX_ROWS]) }}</p>
            @endif
        @endif
    @endif
    @if ($byTime && $period->isAll() && $filters['raster'] === \App\Services\BackendStats::RASTER_AUTO)
        <p class="hint">{{ __('stats.backend.all_months_hint', ['months' => \App\Services\Stats::MONTHS]) }}</p>
    @endif
    @if ($byTime && $report['unit'] === 'week')
        <p class="hint">{{ __('stats.backend.week_hint') }}</p>
    @endif
    <p class="hint">{{ __('stats.backend.amount_hint') }}</p>
    <p class="hint">{{ __('stats.backend.gifts_hint') }}</p>
</section>
@endsection

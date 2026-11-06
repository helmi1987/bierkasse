{{--
    Fixed comparison KPIs (1.30.0), independent of the selected period. Expects $comparison
    (Stats::comparison()): month, previous_month, change (percent or null), year, previous_year.
--}}
@php
    $change = $comparison['change'] === null ? null : round($comparison['change']);
    $changeText = match (true) {
        $change === null => '–',
        $change > 0 => '+'.number_format($change, 0, '.', "'").' %',
        $change < 0 => '−'.number_format(abs($change), 0, '.', "'").' %',
        default => '0 %',
    };
@endphp
<h3 class="kpis-heading">{{ __('stats.compare') }}</h3>
<div class="kpis compare" data-testid="stats-compare">
    <div class="kpi" data-testid="compare-month"><span>{{ __('stats.this_month') }}</span><strong>{{ $comparison['month'] }}</strong></div>
    <div class="kpi" data-testid="compare-previous-month"><span>{{ __('stats.previous_month') }}</span><strong>{{ $comparison['previous_month'] }}</strong></div>
    <div class="kpi" data-testid="compare-change"><span>{{ __('stats.change') }}</span><strong>{{ $changeText }}</strong></div>
    <div class="kpi" data-testid="compare-year"><span>{{ __('stats.this_year') }}</span><strong>{{ $comparison['year'] }}</strong></div>
    <div class="kpi" data-testid="compare-previous-year"><span>{{ __('stats.previous_year') }}</span><strong>{{ $comparison['previous_year'] }}</strong></div>
</div>

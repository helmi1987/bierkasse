{{--
    Period bar of the statistics (1.30.0): tabs, previous/next for week/month/quarter/year, date form for "frei".
    Plain GET links and form (no JS). Expects $period (App\Support\StatsPeriod); optional $periodRoute
    (route name, default "stats") so a backend page can reuse it, and optional $extraQuery (GET parameters of
    other filters, 1.31.0) merged into every link and sent as hidden fields with the custom form; optional
    $hintKey (1.33.0/1.33.1): help toggle after the submit button and collapsible custom-period hint below the form
    (user page only, the backend keeps the plain hint).
--}}
@php
    $periodRoute ??= 'stats';
    $extraQuery ??= [];
    $previousPeriod = $period->previous();
    $nextPeriod = $period->next();
@endphp
<nav class="period-bar" aria-label="{{ __('stats.period.nav') }}" data-testid="period-bar">
    <div class="row-buttons period-tabs">
        @foreach (\App\Support\StatsPeriod::KINDS as $kind)
            <a @class(['btn', 'small', 'primary' => $period->kind === $kind]) href="{{ route($periodRoute, ($kind === \App\Support\StatsPeriod::ALL ? [] : ['zeitraum' => $kind]) + $extraQuery) }}" @if ($period->kind === $kind) aria-current="page" @endif>{{ __('stats.period.'.$kind) }}</a>
        @endforeach
    </div>
    <div class="period-nav">
        @if ($previousPeriod)
            <a class="btn small" href="{{ route($periodRoute, $previousPeriod->query() + $extraQuery) }}" rel="prev" data-testid="period-prev" aria-label="{{ __('stats.period.previous') }}: {{ $previousPeriod->label() }}">← {{ __('stats.period.previous') }}</a>
        @endif
        <h2 class="period-label" data-testid="period-label">{{ $period->label() }}</h2>
        @if ($nextPeriod)
            <a class="btn small" href="{{ route($periodRoute, $nextPeriod->query() + $extraQuery) }}" rel="next" data-testid="period-next" aria-label="{{ __('stats.period.next') }}: {{ $nextPeriod->label() }}">{{ __('stats.period.next') }} →</a>
        @endif
    </div>
    @if ($period->kind === \App\Support\StatsPeriod::CUSTOM)
        <form method="get" action="{{ route($periodRoute) }}" class="inline-form period-form" data-testid="period-form">
            <input type="hidden" name="zeitraum" value="{{ \App\Support\StatsPeriod::CUSTOM }}">
            @foreach ($extraQuery as $extraName => $extraValue)
                <input type="hidden" name="{{ $extraName }}" value="{{ $extraValue }}">
            @endforeach
            <label for="period-from">{{ __('stats.period.from') }}</label>
            <input type="date" id="period-from" name="von" value="{{ $period->from->format('Y-m-d') }}">
            <label for="period-to">{{ __('stats.period.to') }}</label>
            <input type="date" id="period-to" name="bis" value="{{ $period->to->format('Y-m-d') }}">
            <button type="submit" class="btn small primary">{{ __('stats.period.show') }}</button>
            @if ($hintKey ?? null)
                <x-hint-toggle :key="$hintKey" />
            @endif
        </form>
        @if ($hintKey ?? null)
            <x-hint :key="$hintKey">{{ __('stats.period.custom_hint', ['years' => \App\Support\StatsPeriod::MAX_YEARS, 'days' => \App\Support\StatsPeriod::DAILY_MAX_DAYS]) }}</x-hint>
        @else
            <p class="hint">{{ __('stats.period.custom_hint', ['years' => \App\Support\StatsPeriod::MAX_YEARS, 'days' => \App\Support\StatsPeriod::DAILY_MAX_DAYS]) }}</p>
        @endif
    @endif
</nav>

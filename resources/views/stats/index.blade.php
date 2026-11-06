@extends('layouts.app')

@section('title', __('stats.title'))

@section('content')
<h1>{{ __('stats.title') }}</h1>

{{-- Parts switched in Admin > Einstellungen > Funktionen (1.29.0). --}}
@if ($nothing)
    <p class="muted">{{ __('stats.nothing_enabled') }}</p>
@endif

@if ($show['totals'])
    <div class="kpis">
        <div class="kpi big"><span>{{ __('stats.total_beers') }}</span><strong>{{ $stats['total_beers'] }}</strong></div>
    </div>
@endif

@if ($show['months'])
    <section class="card">
        <h2>{{ __('stats.per_month') }}</h2>
        {!! $monthChart !!}
    </section>
@endif

@if ($show['weekdays'] || $show['ranking'])
<div @class(['grid-2' => $show['weekdays'] && $show['ranking']])>
    @if ($show['weekdays'])
        <section class="card">
            <h2>{{ __('stats.per_weekday') }}</h2>
            {!! $weekdayChart !!}
        </section>
    @endif
    @if ($show['ranking'])
        <section class="card">
            <h2>{{ __('stats.ranking') }}</h2>
            @if (empty($stats['ranking']))
                <p class="muted">{{ __('stats.ranking_empty') }}</p>
            @else
                <ol class="ranking">
                    @foreach ($stats['ranking'] as $row)
                        <li @class(['me' => $row['user_id'] === auth()->id(), 'rank-'.$row['rank'] => $row['rank'] <= 3])>
                            <span class="rank">{{ $row['rank'] }}</span>
                            <span class="name">{{ $row['name'] }}</span>
                            @if ($show['ranking_counts'])
                                <strong>{{ $row['beers'] }}</strong>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
            <p class="hint">{{ __('stats.ranking_hint') }}</p>
        </section>
    @endif
</div>
@endif
@endsection

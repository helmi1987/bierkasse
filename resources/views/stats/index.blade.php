@extends('layouts.app')

@section('title', __('stats.title'))

@section('content')
<h1>{{ __('stats.title') }}</h1>

<div class="kpis">
    <div class="kpi big"><span>{{ __('stats.total_beers') }}</span><strong>{{ $stats['total_beers'] }}</strong></div>
</div>

<section class="card">
    <h2>{{ __('stats.per_month') }}</h2>
    {!! $monthChart !!}
</section>

<div class="grid-2">
    <section class="card">
        <h2>{{ __('stats.per_weekday') }}</h2>
        {!! $weekdayChart !!}
    </section>
    <section class="card">
        <h2>{{ __('stats.ranking') }}</h2>
        @if (empty($stats['ranking']))
            <p class="muted">{{ __('stats.ranking_empty') }}</p>
        @else
            <ol class="ranking">
                @foreach ($stats['ranking'] as $row)
                    <li @class(['me' => $row['user_id'] === auth()->id()])>
                        <span class="rank">{{ $loop->iteration }}</span>
                        <span class="name">{{ $row['name'] }}</span>
                        <strong>{{ $row['beers'] }}</strong>
                    </li>
                @endforeach
            </ol>
        @endif
        <p class="hint">{{ __('stats.ranking_hint') }}</p>
    </section>
</div>
@endsection

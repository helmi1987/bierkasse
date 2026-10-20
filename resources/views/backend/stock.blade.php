@extends('layouts.app')

@section('title', __('stock.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('stock.title') }}</h1>
<p class="muted">{{ __('stock.intro') }}</p>

@if ($low)
    <div class="alert warn" role="alert">{{ __('stock.low_alert', ['stock' => $stock, 'min' => $minimum]) }}</div>
@endif

<div class="kpis">
    <div class="kpi"><span>{{ __('stock.stock') }}</span><strong @class(['neg' => $low || $stock < 0])>{{ trans_choice('stock.beers', $stock, ['count' => $stock]) }}</strong>
        <small>{{ $minimum > 0 ? __('stock.minimum_short', ['min' => $minimum]) : __('stock.minimum_off') }}</small></div>
</div>

<div class="grid-2">
    <section class="card">
        <h2>{{ __('stock.correct') }}</h2>
        <form method="post" action="{{ route('backend.stock.correct') }}" class="stack">
            @csrf
            <div class="field">
                <label for="counted">{{ __('stock.counted') }}</label>
                <input type="number" id="counted" name="counted" step="1" value="{{ old('counted', $stock) }}" inputmode="numeric" required>
            </div>
            <div class="field">
                <label for="stock-note">{{ __('stock.note') }}</label>
                <input type="text" id="stock-note" name="note" value="{{ old('note') }}" maxlength="500">
            </div>
            <button type="submit" class="btn primary">{{ __('stock.correct_submit') }}</button>
        </form>
    </section>

    <section class="card">
        <h2>{{ __('stock.minimum') }}</h2>
        <form method="post" action="{{ route('backend.stock.minimum') }}" class="stack">
            @csrf
            @method('PUT')
            <div class="field">
                <label for="minimum">{{ __('stock.minimum') }}</label>
                <input type="number" id="minimum" name="minimum" min="0" step="1" value="{{ old('minimum', $minimum) }}" inputmode="numeric" required>
                <div class="hint">{{ __('stock.minimum_hint') }}</div>
            </div>
            <button type="submit" class="btn">{{ __('stock.save') }}</button>
        </form>
    </section>
</div>

<section class="card">
    <h2>{{ __('stock.corrections') }}</h2>
    @if ($corrections->isEmpty())
        <p class="muted">{{ __('stock.corrections_none') }}</p>
    @else
        <ul class="list">
            @foreach ($corrections as $c)
                <li class="wrap-row">
                    <span>
                        <span class="tx-date">{{ local_time($c->created_at) }}</span><br>
                        {{ __('stock.correction_row', ['counted' => $c->counted, 'delta' => sprintf('%+d', $c->delta)]) }}
                        @if ($c->note) · {{ $c->note }}@endif
                        <span class="muted">{{ __('stock.by') }} {{ $c->creator?->username ?? '–' }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
        {{ $corrections->links('partials.pagination') }}
    @endif
</section>
@endsection

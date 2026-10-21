@extends('layouts.app')

@section('title', __('round.backend_title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('round.backend_title') }}</h1>
<p class="muted">{{ __('round.backend_intro') }}</p>

<form method="post" action="{{ route('backend.rounds.update') }}" class="card stack">
    @csrf
    @method('PUT')
    <label class="switch"><input type="checkbox" name="rounds_enabled" value="1" @checked($allowed)> <span>{{ __('round.allowed') }}</span></label>
    <div class="hint">{{ __('round.allowed_hint') }}</div>
    <div class="row-buttons">
        <button type="submit" class="btn primary">{{ __('round.save') }}</button>
    </div>
</form>

<section class="card">
    <h2>{{ __('round.list') }}</h2>
    <p class="muted">{{ __('round.list_total', ['beers' => $total['beers'], 'amount' => chf($total['amount'])]) }}</p>
    @if ($rounds->isEmpty())
        <p class="muted">{{ __('round.list_none') }}</p>
    @else
        <ul class="list">
            @foreach ($rounds as $round)
                <li class="wrap-row">
                    <span>
                        <span class="tx-date">{{ local_time($round->created_at) }}</span><br>
                        {{ $round->user?->displayName() ?? '?' }} · {{ trans_choice('round.beers', $round->quantity, ['count' => $round->quantity]) }}<br>
                        <small class="muted">{{ __('round.tx_for', ['names' => \App\Services\Rounds::recipientsText($round)]) }}@if ($round->note) · «{{ $round->note }}»@endif</small>
                    </span>
                    <strong class="amount neg">{{ chf($round->amount) }}</strong>
                </li>
            @endforeach
        </ul>
        {{ $rounds->links('partials.pagination') }}
    @endif
</section>
@endsection

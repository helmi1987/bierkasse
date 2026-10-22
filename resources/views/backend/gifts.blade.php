@extends('layouts.app')

@section('title', __('gift.backend_title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('gift.backend_title') }}</h1>
<p class="muted">{{ __('gift.backend_intro') }}</p>

<form method="post" action="{{ route('backend.gifts.update') }}" class="card stack">
    @csrf
    @method('PUT')
    <label class="switch"><input type="checkbox" name="gifts_enabled" value="1" @checked($allowed)> <span>{{ __('gift.allowed') }}</span></label>
    <div class="hint">{{ __('gift.allowed_hint') }}</div>
    <div class="field">
        <label for="gift_undo_minutes">{{ __('gift.undo_minutes') }}</label>
        <input type="number" id="gift_undo_minutes" name="gift_undo_minutes" value="{{ old('gift_undo_minutes', $undoMinutes) }}" min="0" max="60" inputmode="numeric" required>
        <div class="hint">{{ __('gift.undo_minutes_hint') }}</div>
    </div>
    <div class="row-buttons">
        <button type="submit" class="btn primary">{{ __('gift.save') }}</button>
    </div>
</form>

<section class="card">
    <h2>{{ __('gift.list') }}</h2>
    <p class="muted">{{ __('gift.list_total', ['beers' => $total['beers'], 'amount' => chf($total['amount'])]) }}</p>
    @if ($gifts->isEmpty())
        <p class="muted">{{ __('gift.list_none') }}</p>
    @else
        <ul class="list">
            @foreach ($gifts as $gift)
                <li class="wrap-row">
                    <span>
                        <span class="tx-date">{{ local_time($gift->created_at) }}</span><br>
                        {{ $gift->user?->displayName() ?? '?' }} · {{ trans_choice('round.beers', $gift->quantity, ['count' => $gift->quantity]) }}<br>
                        <small class="muted">{{ __('gift.tx_to', ['names' => \App\Services\Gifts::recipientsText($gift)]) }}@if ($gift->note) · «{{ $gift->note }}»@endif</small>
                    </span>
                    <strong class="amount neg">{{ chf($gift->amount) }}</strong>
                </li>
            @endforeach
        </ul>
        {{ $gifts->links('partials.pagination') }}
    @endif
</section>
@endsection

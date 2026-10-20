@extends('layouts.app')

@section('title', __('deposit_suggestions.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('deposit_suggestions.title') }}</h1>
<p class="muted">{{ __('deposit_suggestions.intro') }}</p>

<form method="post" action="{{ route('backend.deposit-suggestions.update') }}" class="card stack" autocomplete="off">
    @csrf
    @method('PUT')
    @php($mode = old('mode', $automatic ? 'auto' : 'manual'))
    <label class="check"><input type="radio" name="mode" value="auto" @checked($mode === 'auto')> {{ __('deposit_suggestions.auto') }}</label>
    <p class="hint">{{ __('deposit_suggestions.auto_hint') }}</p>
    <label class="check"><input type="radio" name="mode" value="manual" @checked($mode === 'manual')> {{ __('deposit_suggestions.manual') }}</label>
    <div class="field">
        <label for="amounts">{{ __('deposit_suggestions.amounts') }}</label>
        <input type="text" id="amounts" name="amounts" value="{{ old('amounts', $input) }}" maxlength="200" inputmode="decimal">
        <div class="hint">{{ __('deposit_suggestions.amounts_hint', ['max' => \App\Support\DepositSuggestions::MAX, 'price' => chf($price)]) }}</div>
    </div>
    <div class="field">
        <label for="default">{{ __('deposit_suggestions.default') }}</label>
        <input type="text" id="default" name="default" value="{{ old('default', $default) }}" maxlength="20" inputmode="decimal">
        <div class="hint">{{ __('deposit_suggestions.default_hint') }}</div>
    </div>
    <button type="submit" class="btn primary">{{ __('deposit_suggestions.save') }}</button>
</form>

<section class="card">
    <h2>{{ __('deposit_suggestions.preview') }} ({{ chf($price) }})</h2>
    <ul class="list">
        @foreach ($preview as $p)
            <li><span>{{ trans_choice('account.suggestion_beers', $p['beers'], ['count' => $p['beers']]) }} · {{ chf($p['amount']) }}</span>
                @if ($p['beers'] === ($defaultBeers ?? $preview[0]['beers']))
                    <span class="badge">{{ __('deposit_suggestions.preselected') }}</span>
                @endif
            </li>
        @endforeach
    </ul>
</section>
@endsection

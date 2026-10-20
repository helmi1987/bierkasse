@extends('layouts.app')

@section('title', __('account.leave_title'))
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('account.leave_title') }}</h1>
<p>{{ __('account.leave_intro') }}</p>
<p class="lead">{{ __('account.leave_balance', ['amount' => chf($balance)]) }}</p>

@if ($blocked)
    <div class="alert warn">{{ __('account.leave_blocked') }} {{ $blocked }}</div>
@else
    <form method="post" action="{{ route('account.leave.store') }}" class="card stack" data-confirm="{{ __('account.leave_confirm') }}">
        @csrf
        <label class="choice">
            <input type="radio" name="choice" value="payout" required @disabled($balance <= 0)>
            <span><strong>{{ __('account.leave_payout') }}</strong><br><span class="muted">{{ __('account.leave_payout_hint') }}</span></span>
        </label>
        <label class="choice">
            <input type="radio" name="choice" value="donate" required>
            <span><strong>{{ __('account.leave_donate') }}</strong><br><span class="muted">{{ __('account.leave_donate_hint') }}</span></span>
        </label>
        <label class="check"><input type="checkbox" name="confirm" value="1" required> {{ __('account.leave_confirm') }}</label>
        <button type="submit" class="btn danger big">{{ __('account.leave_submit') }}</button>
    </form>
@endif
@endsection

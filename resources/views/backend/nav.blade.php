@extends('layouts.app')

@section('title', __('backend.nav.nav'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('backend.nav.nav') }}</h1>

<form method="post" action="{{ route('backend.nav.update') }}" class="card stack">
    @csrf
    @method('PUT')
    <p class="hint">{{ __('backend.nav_intro') }}</p>
    @include('partials.nav-order', ['groups' => $groups, 'orders' => $orders])
    <button type="submit" class="btn primary">{{ __('account.save') }}</button>
</form>
<form method="post" action="{{ route('backend.nav.reset') }}" class="inline" data-confirm="{{ __('backend.nav_reset_confirm') }}">
    @csrf
    @method('DELETE')
    <button type="submit" class="btn small">{{ __('backend.nav_reset') }}</button>
</form>
@endsection

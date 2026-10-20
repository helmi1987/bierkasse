@extends('layouts.app')

@section('title', __('sso.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('sso.title') }}</h1>
<p class="muted">{{ __('sso.intro') }}</p>

<div class="row-buttons">
    @foreach (\App\Models\SsoProvider::TYPES as $type)
        <a class="btn" href="{{ route('backend.sso.create', ['type' => $type]) }}">{{ __('sso.new_type', ['type' => __('sso.types.'.$type)]) }}</a>
    @endforeach
</div>

<section class="card">
    @if ($providers->isEmpty())
        <p class="muted">{{ __('sso.none') }}</p>
    @else
        <ul class="list">
            @foreach ($providers as $provider)
                <li>
                    <span><a href="{{ route('backend.sso.edit', $provider) }}"><strong>{{ $provider->name }}</strong></a>
                        <span class="muted">· {{ __('sso.types.'.$provider->type) }} · {{ __('sso.users') }}: {{ $provider->identities_count }}</span></span>
                    @if ($provider->isUsable())
                        <span class="pill ok">{{ __('sso.status_active') }}</span>
                    @elseif ($provider->enabled)
                        <span class="pill warn">{{ __('sso.status_incomplete') }}</span>
                    @else
                        <span class="pill">{{ __('sso.status_off') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
@endsection

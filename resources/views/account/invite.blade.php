@extends('layouts.app')

@section('title', __('account.invite_title'))
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('account.invite_title') }}</h1>
<section class="card invite">
    <p>{{ __('account.invite_intro') }}</p>
    <img src="{{ $qr }}" alt="QR" width="480" height="480" class="qr invite-qr">
    <p class="label">{{ __('account.invite_url') }}</p>
    <p class="invite-url" id="invite-url">{{ $url }}</p>
    <div class="row-buttons">
        <button type="button" class="btn" data-copy="#invite-url" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button>
        <button type="button" class="btn primary" data-share="#invite-url"
                data-share-title="{{ __('account.invite_share_title', ['app' => $appName]) }}"
                data-share-text="{{ __('account.invite_share_text', ['app' => $appName]) }}"
                data-copied="{{ __('account.copied') }}">{{ __('account.invite_share') }}</button>
    </div>
    <p class="muted">{{ __('account.invite_note') }}</p>
</section>
<p><a href="{{ route('account.dashboard') }}">← {{ __('site.nav.account') }}</a></p>
@endsection

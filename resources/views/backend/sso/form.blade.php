@extends('layouts.app')

@section('title', $provider->exists ? __('sso.edit_title', ['name' => $provider->name]) : __('sso.new'))

@section('content')
@include('partials.backend-nav')
@php($type = old('type', $provider->type ?: 'oidc'))
@php($preset = in_array($type, \App\Models\SsoProvider::PRESET_TYPES, true))
<h1>{{ $provider->exists ? __('sso.edit_title', ['name' => $provider->name]) : __('sso.new_type', ['type' => __('sso.types.'.$type)]) }}</h1>

<div class="card">
    <p><strong>{{ __('sso.redirect_uri') }}:</strong> <code id="redirect-uri">{{ $redirectUri }}</code>
        <button type="button" class="btn small" data-copy="#redirect-uri" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button></p>
    <p class="hint">{{ __('sso.client_hint') }}</p>
    @if (\Illuminate\Support\Facades\Lang::has('sso.setup_help.'.$type))
        <p class="hint">{{ __('sso.setup_help.'.$type) }}</p>
    @endif
</div>

<form method="post" action="{{ $provider->exists ? route('backend.sso.update', $provider) : route('backend.sso.store') }}" class="card stack" autocomplete="off">
    @csrf
    @if ($provider->exists)
        @method('PUT')
    @endif
    <input type="hidden" name="type" value="{{ $type }}">
    <label class="switch"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $provider->enabled))> <span>{{ __('sso.enabled') }}</span></label>

    <div class="grid-2">
        <div class="field">
            <label for="name">{{ __('sso.name') }}</label>
            <input type="text" id="name" name="name" value="{{ old('name', $provider->name) }}" required>
        </div>
        <div class="field">
            <label for="slug">{{ __('sso.slug') }}</label>
            <input type="text" id="slug" name="slug" value="{{ old('slug', $provider->slug) }}" pattern="[a-z0-9\-]+">
            <div class="hint">{{ __('sso.slug_hint') }}</div>
        </div>
        @if ($type === 'microsoft')
            <div class="field">
                <label for="tenant">{{ __('sso.tenant') }}</label>
                <input type="text" id="tenant" name="tenant" value="{{ old('tenant', $provider->tenant ?: 'common') }}">
                <div class="hint">{{ __('sso.tenant_hint') }}</div>
            </div>
        @endif
        @if ($type === 'oidc')
            <div class="field">
                <label for="discovery_url">{{ __('sso.discovery_url') }}</label>
                <input type="url" id="discovery_url" name="discovery_url" value="{{ old('discovery_url', $provider->discovery_url) }}">
                <div class="hint">{{ __('sso.discovery_hint') }}</div>
            </div>
        @endif
        <div class="field">
            <label for="client_id">{{ __('sso.client_id') }}</label>
            <input type="text" id="client_id" name="client_id" value="{{ old('client_id', $provider->client_id) }}">
        </div>
        <div class="field">
            <label for="client_secret">{{ __('sso.client_secret') }}</label>
            <input type="password" id="client_secret" name="client_secret" autocomplete="new-password" placeholder="{{ $provider->client_secret ? __('sso.secret_stored') : '' }}">
            <div class="hint">{{ __('sso.secret_hint') }}</div>
        </div>
    </div>
    @if ($preset)
        <p class="hint">{{ __('sso.preset_hint') }}</p>
    @endif
    @if ($provider->discovered_at)
        <p class="hint">{{ __('sso.discovered_at', ['date' => local_time($provider->discovered_at)]) }}</p>
    @endif

    <details>
        <summary>{{ __('sso.advanced') }}</summary>
        <div class="grid-2">
            <div class="field">
                <label for="scopes">{{ __('sso.scopes') }}</label>
                <input type="text" id="scopes" name="scopes" value="{{ old('scopes', $provider->scopes) }}" required>
            </div>
            <div class="field">
                <label for="issuer">{{ __('sso.issuer') }}</label>
                <input type="text" id="issuer" name="issuer" value="{{ old('issuer', $provider->issuer) }}">
            </div>
            <div class="field">
                <label for="authorize_url">{{ __('sso.authorize_url') }}</label>
                <input type="url" id="authorize_url" name="authorize_url" value="{{ old('authorize_url', $provider->authorize_url) }}">
            </div>
            <div class="field">
                <label for="token_url">{{ __('sso.token_url') }}</label>
                <input type="url" id="token_url" name="token_url" value="{{ old('token_url', $provider->token_url) }}">
            </div>
            <div class="field">
                <label for="userinfo_url">{{ __('sso.userinfo_url') }}</label>
                <input type="url" id="userinfo_url" name="userinfo_url" value="{{ old('userinfo_url', $provider->userinfo_url) }}">
            </div>
            <div class="field">
                <label for="token_auth">{{ __('sso.token_auth') }}</label>
                <select id="token_auth" name="token_auth">
                    <option value="post" @selected(old('token_auth', $provider->token_auth) === 'post')>client_secret_post</option>
                    <option value="basic" @selected(old('token_auth', $provider->token_auth) === 'basic')>client_secret_basic</option>
                </select>
            </div>
            <div class="field">
                <label for="subject_claim">{{ __('sso.claim_subject') }}</label>
                <input type="text" id="subject_claim" name="subject_claim" value="{{ old('subject_claim', $provider->subject_claim) }}" required>
            </div>
            <div class="field">
                <label for="email_claim">{{ __('sso.claim_email') }}</label>
                <input type="text" id="email_claim" name="email_claim" value="{{ old('email_claim', $provider->email_claim) }}" required>
            </div>
            <div class="field">
                <label for="label_claim">{{ __('sso.claim_label') }}</label>
                <input type="text" id="label_claim" name="label_claim" value="{{ old('label_claim', $provider->label_claim) }}" required>
            </div>
            <div class="field">
                <label for="sort">{{ __('sso.sort') }}</label>
                <input type="number" id="sort" name="sort" value="{{ old('sort', $provider->sort ?? 0) }}">
            </div>
        </div>
        <label class="check"><input type="checkbox" name="use_pkce" value="1" @checked(old('use_pkce', $provider->use_pkce))> {{ __('sso.use_pkce') }}</label>
    </details>

    <div class="row-buttons">
        <button type="submit" class="btn primary">{{ __('sso.save') }}</button>
        @if (! in_array($type, ['github', 'facebook'], true))
            <button type="submit" name="rediscover" value="1" class="btn">{{ __('sso.save_rediscover') }}</button>
        @endif
    </div>
</form>

@if ($provider->exists)
    <form method="post" action="{{ route('backend.sso.destroy', $provider) }}" data-confirm="{{ __('sso.delete_confirm') }}">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn danger">{{ __('sso.delete') }}</button>
    </form>
@endif
@endsection

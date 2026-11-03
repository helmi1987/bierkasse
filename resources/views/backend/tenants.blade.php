@extends('layouts.app')

@section('title', __('tenants.title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('tenants.title') }}</h1>

@if (! $multi)
    {{-- Single mode: only the switch to multi mode. --}}
    <section class="card stack" data-testid="tenancy-single">
        <h2>{{ __('tenants.single_title') }}</h2>
        <p>{{ __('tenants.single_intro') }}</p>
        <p class="hint">{{ __('tenants.enable_multi_hint', ['name' => $current?->name]) }}</p>
        <form method="post" action="{{ route('backend.tenants.mode') }}" class="stack" data-confirm="{{ __('tenants.enable_multi_confirm') }}">
            @csrf
            <input type="hidden" name="mode" value="multi">
            <div><button type="submit" class="btn primary">{{ __('tenants.enable_multi') }}</button></div>
        </form>
    </section>
@elseif (! $superadmin)
    {{-- Multi mode, tenant admin: the own tenant, name only. --}}
    @php($own = $tenants->first() ?? $current)
    <section class="card stack" data-testid="tenant-own">
        <h2>{{ $own->name }}</h2>
        <p class="muted">{{ __('tenants.slug') }}: <code>{{ $own->slug }}</code> · {{ __('tenants.users_count', ['count' => $own->users_count ?? 0]) }}</p>
        <div>
            <strong>{{ __('tenants.link') }}</strong><br>
            <a href="{{ route('login', [\App\Services\Tenancy::QUERY => $own->slug]) }}">{{ route('login', [\App\Services\Tenancy::QUERY => $own->slug]) }}</a><br>
            <span class="muted">{{ \App\Services\Tenancy::hostUrl($own) }}</span>
            <p class="hint">{{ __('tenants.link_hint') }}</p>
        </div>
        <form method="post" action="{{ route('backend.tenants.update', $own) }}" class="stack">
            @csrf
            @method('PUT')
            <input type="hidden" name="slug" value="{{ $own->slug }}">
            <div class="field">
                <label for="tenant-name">{{ __('tenants.name') }}</label>
                <input type="text" id="tenant-name" name="name" value="{{ old('name', $own->name) }}" maxlength="{{ \App\Models\Tenant::NAME_MAX }}" required>
            </div>
            <div><button type="submit" class="btn">{{ __('tenants.rename') }}</button></div>
        </form>
    </section>
@else
    {{-- Multi mode, superadmin: all tenants. --}}
    <section class="card stack" data-testid="tenant-list">
        <h2>{{ __('tenants.list') }}</h2>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('tenants.name') }}</th><th>{{ __('tenants.link') }}</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($tenants as $tenant)
                        @php($isCurrent = $current !== null && $tenant->id === $current->id)
                        @php($isHome = $home !== null && $tenant->id === $home->id)
                        <tr data-testid="tenant-row">
                            <td>
                                <strong>{{ $tenant->name }}</strong>
                                @if ($isCurrent) <span class="pill ok">{{ __('tenants.current') }}</span>@endif
                                @if ($isHome) <span class="pill">{{ __('tenants.home') }}</span>@endif
                                <br><small class="muted"><code>{{ $tenant->slug }}</code> · {{ __('tenants.users_count', ['count' => $tenant->users_count ?? 0]) }}</small>
                            </td>
                            <td>
                                <a href="{{ route('login', [\App\Services\Tenancy::QUERY => $tenant->slug]) }}">{{ route('login', [\App\Services\Tenancy::QUERY => $tenant->slug]) }}</a><br>
                                <small class="muted">{{ \App\Services\Tenancy::hostUrl($tenant) }}</small>
                            </td>
                            <td>
                                @unless ($isCurrent)
                                    <form method="post" action="{{ route('backend.tenants.switch', $tenant) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="btn small">{{ __('tenants.switch') }}</button>
                                    </form>
                                @endunless
                                <details>
                                    <summary>{{ __('tenants.rename') }}</summary>
                                    <form method="post" action="{{ route('backend.tenants.update', $tenant) }}" class="stack">
                                        @csrf
                                        @method('PUT')
                                        <div class="field">
                                            <label for="name-{{ $tenant->id }}">{{ __('tenants.name') }}</label>
                                            <input type="text" id="name-{{ $tenant->id }}" name="name" value="{{ $tenant->name }}" maxlength="{{ \App\Models\Tenant::NAME_MAX }}" required>
                                        </div>
                                        <div class="field">
                                            <label for="slug-{{ $tenant->id }}">{{ __('tenants.slug') }}</label>
                                            <input type="text" id="slug-{{ $tenant->id }}" name="slug" value="{{ $tenant->slug }}" maxlength="32" pattern="[a-z0-9]([a-z0-9\-]{0,30}[a-z0-9])?" required>
                                        </div>
                                        <div><button type="submit" class="btn small">{{ __('tenants.rename') }}</button></div>
                                    </form>
                                </details>
                                @if (! $isHome && $tenants->count() > 1)
                                    <details>
                                        <summary>{{ __('tenants.delete') }}</summary>
                                        <form method="post" action="{{ route('backend.tenants.destroy', $tenant) }}" class="stack" data-confirm="{{ __('tenants.delete_confirm', ['name' => $tenant->name]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <p class="hint">{{ __('tenants.delete_hint') }}</p>
                                            <div class="field">
                                                <label for="confirm-{{ $tenant->id }}">{{ __('tenants.delete_confirm_label') }}</label>
                                                <input type="text" id="confirm-{{ $tenant->id }}" name="confirm" placeholder="{{ $tenant->slug }}" autocomplete="off" required>
                                            </div>
                                            <div><button type="submit" class="btn danger small">{{ __('tenants.delete') }}</button></div>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="hint">{{ __('tenants.link_hint') }}</p>
    </section>

    <section class="card stack" id="neu">
        <h2>{{ __('tenants.create') }}</h2>
        <p class="hint">{{ __('tenants.create_hint') }}</p>
        <form method="post" action="{{ route('backend.tenants.store') }}" class="stack" autocomplete="off">
            @csrf
            <div class="grid-2">
                <div class="field">
                    <label for="new-name">{{ __('tenants.name') }}</label>
                    <input type="text" id="new-name" name="name" value="{{ old('name') }}" maxlength="{{ \App\Models\Tenant::NAME_MAX }}" required>
                </div>
                <div class="field">
                    <label for="new-slug">{{ __('tenants.slug') }}</label>
                    <input type="text" id="new-slug" name="slug" value="{{ old('slug') }}" maxlength="32" pattern="[a-z0-9]([a-z0-9\-]{0,30}[a-z0-9])?" required>
                    <div class="hint">{{ __('tenants.slug_hint', ['host' => $baseHost]) }}</div>
                </div>
                <div class="field">
                    <label for="new-admin-username">{{ __('tenants.admin_username') }}</label>
                    <input type="text" id="new-admin-username" name="admin_username" value="{{ old('admin_username') }}" pattern="[A-Za-z0-9._\-]{3,32}" required>
                </div>
                <div class="field">
                    <label for="new-admin-email">{{ __('tenants.admin_email') }}</label>
                    <input type="email" id="new-admin-email" name="admin_email" value="{{ old('admin_email') }}" maxlength="255" required>
                </div>
                <div class="field">
                    <label for="new-admin-password">{{ __('tenants.admin_password') }}</label>
                    <input type="password" id="new-admin-password" name="admin_password" autocomplete="new-password" minlength="12" required>
                </div>
                <div class="field">
                    <label for="new-admin-password-confirmation">{{ __('tenants.admin_password_confirmation') }}</label>
                    <input type="password" id="new-admin-password-confirmation" name="admin_password_confirmation" autocomplete="new-password" minlength="12" required>
                </div>
            </div>
            <div><button type="submit" class="btn primary">{{ __('tenants.create_submit') }}</button></div>
        </form>
    </section>

    <section class="card stack" data-testid="superadmins">
        <h2>{{ __('tenants.superadmins') }}</h2>
        <ul class="list">
            @foreach ($superadmins as $sa)
                <li>{{ $sa->username }} <span class="muted">({{ $sa->tenant?->name ?? '–' }})</span></li>
            @endforeach
        </ul>
        <p class="hint">{{ __('tenants.superadmins_hint') }}</p>
    </section>

    <section class="card stack">
        <h2>{{ __('tenants.disable_multi') }}</h2>
        <p class="hint">{{ __('tenants.disable_multi_hint') }}</p>
        @if ($tenants->count() === 1)
            <form method="post" action="{{ route('backend.tenants.mode') }}" class="stack" data-confirm="{{ __('tenants.disable_multi_confirm') }}">
                @csrf
                <input type="hidden" name="mode" value="single">
                <div><button type="submit" class="btn">{{ __('tenants.disable_multi') }}</button></div>
            </form>
        @else
            <p class="muted">{{ __('tenants.disable_multi_blocked') }}</p>
        @endif
    </section>

    @if (! empty($backups))
        <section class="card stack">
            <h2>{{ __('tenants.backups') }}</h2>
            <ul class="list">
                @foreach (array_slice($backups, 0, 5) as $b)
                    <li>{{ $b['name'] }} <span class="muted">· {{ \Illuminate\Support\Number::fileSize($b['size'], 1) }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
@endif
@endsection

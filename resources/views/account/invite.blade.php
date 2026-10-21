@extends('layouts.app')

@section('title', __('account.invite_title'))
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('account.invite_title') }}</h1>

@if ($new)
    <section class="card invite">
        <h2>{{ __('account.invitation_new_title') }}</h2>
        <p>{{ __('account.invitation_new_intro') }}</p>
        <img src="{{ $newQr }}" alt="QR" width="480" height="480" class="qr invite-qr">
        <p class="label">{{ __('account.invite_url') }}</p>
        <p class="invite-url" id="invite-url">{{ $newUrl }}</p>
        <div class="row-buttons">
            <button type="button" class="btn" data-copy="#invite-url" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button>
            <button type="button" class="btn primary" data-share="#invite-url"
                    data-share-title="{{ __('account.invite_share_title', ['app' => $appName]) }}"
                    data-share-text="{{ __('account.invite_share_text', ['app' => $appName]) }}"
                    data-copied="{{ __('account.copied') }}">{{ __('account.invite_share') }}</button>
        </div>
        <p><strong>{{ __('account.invitation_valid_until', ['date' => local_time($new->expires_at)]) }}</strong></p>
        <p class="muted">{{ __('account.invitation_once_note') }}</p>
    </section>
@endif

<section class="card stack">
    @unless ($new)
        <p>{{ __('account.invitation_intro', ['days' => $days]) }}</p>
    @endunless
    @if ($openCount < $max)
        <form method="post" action="{{ route('account.invite.store') }}" class="stack">
            @csrf
            {!! \App\Support\FormToken::field('invite') !!}
            <button type="submit" class="btn primary big">{{ __('account.invitation_create') }}</button>
        </form>
    @else
        <div class="alert warn">{{ __('account.invitation_limit', ['max' => $max]) }}</div>
    @endif
    <p class="hint">{{ __('account.invitation_hash_note') }}</p>
</section>

<section class="card">
    <h2>{{ __('account.invitation_list') }}</h2>
    @if ($invitations->isEmpty())
        <p class="muted">{{ __('account.invitation_none') }}</p>
    @else
        <ul class="list">
            @foreach ($invitations as $invitation)
                @php($status = $invitation->status())
                <li class="wrap-row">
                    <span>
                        <span class="tx-date">{{ __('account.invitation_created', ['date' => local_time($invitation->created_at)]) }}</span><br>
                        @if ($status === \App\Models\Invitation::STATUS_USED)
                            <span class="pill ok">{{ __('account.invitation_status.used', ['user' => $invitation->usedBy?->username ?? '?']) }}</span>
                            <small class="muted">{{ local_time($invitation->used_at) }}</small>
                        @elseif ($status === \App\Models\Invitation::STATUS_EXPIRED)
                            <span class="pill">{{ __('account.invitation_status.expired') }}</span>
                            <small class="muted">{{ local_time($invitation->expires_at) }}</small>
                        @else
                            <span class="pill warn">{{ __('account.invitation_status.open') }}</span>
                            <small class="muted">{{ __('account.invitation_expires', ['date' => local_time($invitation->expires_at)]) }}</small>
                        @endif
                    </span>
                    @if ($invitation->used_at === null)
                        <form method="post" action="{{ route('account.invite.destroy', $invitation) }}" class="inline" data-confirm="{{ __('account.invitation_revoke_confirm') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn small ghost">{{ __('account.invitation_revoke') }}</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>

@if ($passwordUrl)
    <section class="card invite">
        <h2>{{ __('account.invite_password_title') }}</h2>
        <p>{{ __('account.invite_intro') }}</p>
        <img src="{{ $passwordQr }}" alt="QR" width="480" height="480" class="qr invite-qr">
        <p class="label">{{ __('account.invite_url') }}</p>
        <p class="invite-url" id="invite-password-url">{{ $passwordUrl }}</p>
        <div class="row-buttons">
            <button type="button" class="btn" data-copy="#invite-password-url" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button>
            <button type="button" class="btn primary" data-share="#invite-password-url"
                    data-share-title="{{ __('account.invite_share_title', ['app' => $appName]) }}"
                    data-share-text="{{ __('account.invite_share_text', ['app' => $appName]) }}"
                    data-copied="{{ __('account.copied') }}">{{ __('account.invite_share') }}</button>
        </div>
        <p class="muted">{{ __('account.invite_note') }}</p>
    </section>
@endif
<p><a href="{{ route('account.dashboard') }}">← {{ __('site.nav.account') }}</a></p>
@endsection

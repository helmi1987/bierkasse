@extends('layouts.app')

@section('title', __('backend.invitations_title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('backend.invitations_title') }}</h1>

<section class="card">
    <p class="muted">{{ __('backend.invitations_mode', ['mode' => __('settings.registration_modes.'.$mode)]) }}
        · {{ __('backend.invitations_open', ['count' => $openCount]) }}</p>
    @if ($mode === \App\Support\AppSettings::MODE_CLOSED)
        <div class="alert warn">{{ __('backend.invitations_closed') }}</div>
    @endif
    @if ($invitations->isEmpty())
        <p class="muted">{{ __('backend.invitations_none') }}</p>
    @else
        <ul class="list">
            @foreach ($invitations as $invitation)
                @php($status = $invitation->status())
                <li class="wrap-row">
                    <span>
                        <span class="tx-date">{{ local_time($invitation->created_at) }}</span><br>
                        {{ __('backend.invitations_by', ['user' => $invitation->creator?->username ?? '?']) }}<br>
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
                        <form method="post" action="{{ route('backend.invitations.destroy', $invitation) }}" class="inline" data-confirm="{{ __('account.invitation_revoke_confirm') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn small ghost">{{ __('account.invitation_revoke') }}</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
        {{ $invitations->links('partials.pagination') }}
    @endif
</section>
@endsection

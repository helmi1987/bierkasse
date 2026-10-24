{{-- Benutzer: user list and invitations (Livewire; refreshed every 10 s while visible). --}}
<div wire:poll.10s.visible>
    @include('livewire.partials.tabs', [
        'tabs' => ['benutzer' => __('backend.tabs.users'), 'einladungen' => __('backend.tabs.invitations')],
        'active' => $tab,
        'route' => 'backend.users.index',
        'label' => __('users.title'),
    ])
    @include('livewire.partials.notice')

    @if ($tab === 'einladungen')
        <section class="card" data-testid="users-invitations">
            <h2>{{ __('backend.invitations_title') }}</h2>
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
                        <li class="wrap-row" wire:key="invitation-{{ $invitation->id }}">
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
                                <button type="button" class="btn small ghost" wire:click="revokeInvitation({{ $invitation->id }})" wire:confirm="{{ __('account.invitation_revoke_confirm') }}" wire:loading.attr="disabled">{{ __('account.invitation_revoke') }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
                {{ $invitations->links('livewire.pagination') }}
            @endif
        </section>
    @else
        <nav class="tabs" aria-label="{{ __('users.status_filter') }}">
            <a href="{{ route('backend.users.index') }}" wire:click.prevent="showStatus('active')" @class(['active' => $status === 'active'])>{{ __('users.active') }}</a>
            <a href="{{ route('backend.users.index', ['status' => 'left']) }}" wire:click.prevent="showStatus('left')" @class(['active' => $status === 'left'])>{{ __('users.left') }}</a>
        </nav>
        <section class="card" data-testid="users-list">
            @if ($users->isEmpty())
                <p class="muted">{{ __('users.none') }}</p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('users.name') }}</th>
                                <th>{{ __('users.role') }}</th>
                                <th class="num">{{ __('users.limit') }}</th>
                                <th class="num">{{ __('users.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $user)
                                @php($balance = $balances[$user->id] ?? 0)
                                <tr wire:key="user-{{ $user->id }}">
                                    <td><a href="{{ route('backend.users.show', $user) }}"><strong>{{ $user->displayName() }}</strong></a>@if ($user->isAdminOnly()) <span class="pill">{{ __('account.admin_only') }}</span>@endif @if ($user->isLocked())<span class="pill bad">{{ __('users.locked_badge') }}</span>@endif<br><span class="muted">{{ $user->username }}</span>@if ($user->staffNoteForList())<br><small class="muted" data-testid="staff-note">{{ $user->staffNoteForList() }}</small>@endif</td>
                                    <td>{{ __('users.roles.'.$user->role) }}</td>
                                    @if ($user->isAdminOnly())
                                        <td class="num muted">–</td>
                                        <td class="num muted">–</td>
                                    @else
                                        <td class="num">{{ $user->overdraft_limit === null ? __('users.limit_default', ['limit' => chf(\App\Support\AppSettings::defaultLimit())]) : chf($user->overdraft_limit) }}</td>
                                        <td @class(['num', 'amount', 'neg' => $balance < 0, 'pos' => $balance > 0])>{{ chf($balance) }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>

{{-- QR-Codes: overview for kassiers and admins, management parts for admins (Livewire; refreshed every 10 s while visible). --}}
<div wire:poll.10s.visible>
    <p class="muted">{{ __('qr.intro_kassier') }}</p>
    <section class="card" data-testid="qr-overview">
        <ul class="list">
            @foreach ($rows as $row)
                @php($off = match ($row['key']) { 'cashbox_cash', 'cashbox_twint' => ! $cashboxEnabled, 'guest' => ! $guestOpen, 'flyer' => ! $registrationOpen, default => false })
                <li class="wrap-row" data-testid="qr-kind-{{ $row['key'] }}" wire:key="qr-kind-{{ $row['key'] }}">
                    <span>
                        <strong>{{ __('qr.kind.'.$row['key']) }}</strong>
                        @if ($off)<span class="pill">{{ __('qr.off') }}</span>@endif
                        <br><small class="muted">{{ __('qr.count_active', ['count' => $row['active']]) }}</small>
                    </span>
                    <a class="btn small" href="{{ $row['url'] }}">{{ __('qr.open') }}</a>
                </li>
            @endforeach
        </ul>
    </section>

    @if ($admin)
        <h2 id="verwaltung">{{ __('qr.manage_title') }}</h2>
        <p class="muted">{{ __('qr.manage_intro') }}</p>
        <p class="hint">{{ __('qr.switches_moved') }} <a href="{{ route('backend.settings') }}#funktionen">{{ __('backend.features_link') }} →</a></p>

        @foreach ($kinds as $group)
            @php($kind = $group['kind'])
            <section class="card stack" id="qr-{{ $kind }}" data-testid="qr-admin-{{ $kind }}" wire:key="qr-admin-{{ $kind }}">
                <h3>{{ __('qr.kind.'.$kind) }}</h3>
                @if ($kind === \App\Models\QrCode::KIND_TWINT && ! $twintEnabled)
                    <p class="hint">{{ __('guest.cashbox_twint_off') }}</p>
                @endif
                @if ($kind === \App\Models\QrCode::KIND_FLYER && ! $registrationOpen)
                    <p class="hint">{{ __('qr.flyer_closed') }}</p>
                @endif
                @include('partials.qr.new', ['kind' => $kind, 'button' => __('qr.new')])
                @if ($group['active']->isEmpty())
                    <p class="muted">{{ __('qr.none_active') }}</p>
                @endif
                <ul class="list">
                    @foreach ($group['active'] as $code)
                        @php($viewUrl = match ($kind) {
                            \App\Models\QrCode::KIND_GUEST => route('backend.guests.qr', ['qr' => $code->id]),
                            \App\Models\QrCode::KIND_FLYER => route('backend.registration.qr', ['qr' => 'flyer-'.$code->id]),
                            default => route('backend.qr-codes.cashbox'),
                        })
                        <li class="wrap-row" wire:key="qr-code-{{ $code->id }}">
                            <span>
                                <strong>{{ $code->displayName() }}</strong><br>
                                <small class="muted">{{ __('qr.meta', ['date' => local_time($code->created_at), 'user' => $code->creator?->username ?? '–']) }}</small>
                            </span>
                            <span class="row-buttons">
                                <a class="btn small" href="{{ $viewUrl }}">{{ __('qr.view') }}</a>
                                @include('partials.qr.manage', ['code' => $code])
                            </span>
                        </li>
                    @endforeach
                </ul>
                @include('partials.qr.history', ['history' => $group['history']])
            </section>
        @endforeach

        <section class="card stack" id="qr-registration" data-testid="qr-admin-registration" wire:key="qr-admin-registration">
            <h3>{{ __('qr.kind.registration') }}</h3>
            <p class="hint">{{ __('settings.codes_intro') }}</p>
            <form method="post" action="{{ route('backend.registration.codes.store') }}" class="inline-form">
                @csrf
                <label for="code_label">{{ __('settings.codes_label') }}</label>
                <input type="text" id="code_label" name="label" maxlength="100" required placeholder="{{ __('settings.codes_label_placeholder') }}">
                <button type="submit" class="btn small">{{ __('settings.codes_create') }}</button>
            </form>
            <ul class="list">
                @foreach ($registrationCodes as $code)
                    <li class="wrap-row" wire:key="registration-code-{{ $code->id }}">
                        <span>
                            <strong>{{ $code->label }}</strong>
                            @if ($code->isLocked())<span class="pill">{{ __('settings.codes_status_locked') }}</span>@endif
                            <br><small class="muted">{{ __('settings.codes_meta', ['user' => $code->creator?->username ?? '?', 'date' => local_time($code->created_at), 'count' => $code->users_count]) }}</small>
                        </span>
                        <span class="row-buttons">
                            @unless ($code->isLocked())
                                <a class="btn small" href="{{ route('backend.registration.qr', ['qr' => $code->id]) }}">{{ __('qr.view') }}</a>
                            @endunless
                            <form method="post" action="{{ route('backend.registration.codes.lock', $code) }}" class="inline">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="locked" value="{{ $code->isLocked() ? 0 : 1 }}">
                                <button type="submit" @class(['btn', 'small', 'danger' => ! $code->isLocked()])>{{ $code->isLocked() ? __('settings.codes_unlock') : __('settings.codes_lock') }}</button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>

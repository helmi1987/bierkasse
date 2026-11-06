{{-- "Entsorgungsbonus" (Livewire since 1.36.0; see App\Livewire\Backend\Disposals). Polls every 10 s while visible;
     only the islands (status, list, ranking) are re-rendered, guide and forms stay as rendered at mount. --}}
<div wire:poll.10s.visible="tick" data-testid="backend-disposals">
<section class="card">
    <details data-testid="disposal-guide" @if (! $enabled) open @endif>
        <summary><h2>{{ __('disposal.guide_heading') }}</h2></summary>
        <ul>
            @foreach (__('disposal.guide') as $paragraph)
                <li>{{ $paragraph }}</li>
            @endforeach
        </ul>
    </details>
</section>

<div class="grid-2">
    <section class="card">
        <h2>{{ __('disposal.settings') }}</h2>
        <form method="post" action="{{ route('backend.disposals.settings') }}" class="stack" data-testid="disposal-settings">
            @csrf
            @method('PUT')
            <div class="field">
                <label class="switch"><input type="checkbox" name="disposal_enabled" value="1" @checked(old('disposal_enabled', $enabled))> <span>{{ __('disposal.enabled') }}</span></label>
                <div class="hint">{{ __('disposal.enabled_hint') }}</div>
            </div>
            <fieldset class="stack">
                <legend>{{ __('disposal.mode') }}</legend>
                <label><input type="radio" name="disposal_mode" value="fixed" @checked(old('disposal_mode', $mode) === 'fixed')> {{ __('disposal.mode_fixed') }}</label>
                <div class="field">
                    <label for="disposal_fixed">{{ __('disposal.fixed') }}</label>
                    <input type="number" id="disposal_fixed" name="disposal_fixed" value="{{ old('disposal_fixed', $fixed) }}" min="1" max="{{ \App\Support\AppSettings::DISPOSAL_BEERS_MAX }}" inputmode="numeric" required>
                    @if ($formErrors->has('disposal_fixed'))<div class="hint neg">{{ $formErrors->first('disposal_fixed') }}</div>@endif
                </div>
                <label><input type="radio" name="disposal_mode" value="random" @checked(old('disposal_mode', $mode) === 'random')> {{ __('disposal.mode_random') }}</label>
                <div class="field">
                    <label for="disposal_random_max">{{ __('disposal.random_max') }}</label>
                    <input type="number" id="disposal_random_max" name="disposal_random_max" value="{{ old('disposal_random_max', $randomMax) }}" min="1" max="{{ \App\Support\AppSettings::DISPOSAL_BEERS_MAX }}" inputmode="numeric" required>
                    @if ($formErrors->has('disposal_random_max'))<div class="hint neg">{{ $formErrors->first('disposal_random_max') }}</div>@endif
                </div>
                @if ($formErrors->has('disposal_mode'))<div class="hint neg">{{ $formErrors->first('disposal_mode') }}</div>@endif
            </fieldset>
            <div class="field">
                <label for="disposal_lock_days">{{ __('disposal.lock_days') }}</label>
                <input type="number" id="disposal_lock_days" name="disposal_lock_days" value="{{ old('disposal_lock_days', $lockDays) }}" min="0" max="{{ \App\Support\AppSettings::DISPOSAL_LOCK_DAYS_MAX }}" inputmode="numeric" required>
                <div class="hint">{{ __('disposal.lock_days_hint') }}</div>
                @if ($formErrors->has('disposal_lock_days'))<div class="hint neg">{{ $formErrors->first('disposal_lock_days') }}</div>@endif
            </div>
            <div class="field">
                <label for="disposal_min_beers">{{ __('disposal.min_beers') }}</label>
                <input type="number" id="disposal_min_beers" name="disposal_min_beers" value="{{ old('disposal_min_beers', $minBeers) }}" min="0" max="{{ \App\Support\AppSettings::DISPOSAL_MIN_BEERS_MAX }}" inputmode="numeric" required>
                <div class="hint">{{ __('disposal.min_beers_hint') }}</div>
                @if ($formErrors->has('disposal_min_beers'))<div class="hint neg">{{ $formErrors->first('disposal_min_beers') }}</div>@endif
            </div>
            <div class="field">
                <label for="disposal_withdraw_minutes">{{ __('disposal.withdraw_minutes') }}</label>
                <input type="number" id="disposal_withdraw_minutes" name="disposal_withdraw_minutes" value="{{ old('disposal_withdraw_minutes', $withdrawMinutes) }}" min="0" max="{{ \App\Support\AppSettings::UNDO_MAX }}" inputmode="numeric" required>
                <div class="hint">{{ __('disposal.withdraw_minutes_hint') }}</div>
                @if ($formErrors->has('disposal_withdraw_minutes'))<div class="hint neg">{{ $formErrors->first('disposal_withdraw_minutes') }}</div>@endif
            </div>
            <div class="field">
                <label class="switch"><input type="checkbox" name="disposal_ranking_public" value="1" @checked(old('disposal_ranking_public', $rankingPublic))> <span>{{ __('disposal.ranking_public') }}</span></label>
                <div class="hint">{{ __('disposal.ranking_public_hint') }}</div>
            </div>
            <button type="submit" class="btn primary">{{ __('disposal.save') }}</button>
        </form>
    </section>

    <div class="stack">
        @island('status', with: $__livewire->data())
        <section class="card" data-testid="disposal-status">
            <h2>{{ __('disposal.status') }}</h2>
            @if (! $enabled)
                <p>{{ __('disposal.status_off') }}</p>
            @else
                <ul class="list">
                    @if ($enabledAt)
                        <li>{{ __('disposal.status_enabled_since', ['date' => local_time($enabledAt)]) }}</li>
                    @endif
                    @if ($last)
                        <li>{{ __('disposal.status_last', ['user' => $last->user?->displayName() ?? '?', 'date' => local_time($last->reported_at)]) }}</li>
                        @if ($lockedUntil)
                            <li>{{ $lockedUntil->isFuture() ? __('disposal.status_locked_until', ['date' => local_time($lockedUntil)]) : __('disposal.status_lock_over') }}</li>
                        @endif
                        @if ($minBeers > 0)
                            <li>{{ __('disposal.status_beers', ['count' => $beersSince, 'min' => $minBeers]) }}</li>
                        @endif
                    @else
                        <li>{{ __('disposal.status_none') }}</li>
                    @endif
                    <li><strong>{{ $blockReason === null ? __('disposal.status_open') : __('disposal.status_blocked') }}</strong></li>
                </ul>
            @endif
        </section>
        @endisland

        @if ($enabled)
            <section class="card">
                <h2>{{ __('disposal.for_user') }}</h2>
                <p class="muted">{{ __('disposal.for_user_intro') }}</p>
                <form method="post" action="{{ route('backend.disposals.report') }}" class="stack" data-testid="disposal-for-user">
                    @csrf
                    <div class="field">
                        <label for="disposal_user">{{ __('disposal.user') }}</label>
                        <select id="disposal_user" name="user_id" required>
                            <option value="">{{ __('disposal.user_choose') }}</option>
                            @foreach ($users as $u)
                                <option value="{{ $u->id }}" @selected((int) old('user_id') === $u->id)>{{ $u->displayName() }}</option>
                            @endforeach
                        </select>
                        @if ($formErrors->has('user_id'))<div class="hint neg">{{ $formErrors->first('user_id') }}</div>@endif
                    </div>
                    <div class="field">
                        <label class="switch"><input type="checkbox" name="override" value="1" @checked(old('override'))> <span>{{ __('disposal.override') }}</span></label>
                        <div class="hint">{{ __('disposal.override_hint') }}</div>
                    </div>
                    <div class="field">
                        <label for="disposal_reason">{{ __('disposal.reason') }}</label>
                        <input type="text" id="disposal_reason" name="reason" value="{{ old('reason') }}" maxlength="500">
                        @if ($formErrors->has('reason'))<div class="hint neg">{{ $formErrors->first('reason') }}</div>@endif
                    </div>
                    <button type="submit" class="btn primary">{{ __('disposal.submit') }}</button>
                </form>
            </section>
        @endif
    </div>
</div>

@island('list', always: true, with: $__livewire->data())
<section class="card">
    <h2>{{ __('disposal.list') }}</h2>
    @if ($disposals->isEmpty())
        <p class="muted">{{ __('disposal.list_none') }}</p>
    @else
        <ul class="list" data-testid="disposal-list">
            @foreach ($disposals as $d)
                <li class="wrap-row" wire:key="disposal-{{ $d->id }}">
                    <span>
                        <span class="tx-date">{{ local_time($d->reported_at) }}</span><br>
                        <strong>{{ $d->user?->displayName() ?? '?' }}</strong>
                        <span @class(['pill', 'warn' => $d->isReported(), 'ok' => $d->isBooked()])>{{ __('disposal.state.'.$d->status) }}</span>
                        · <span class="muted">{{ $d->isReportedForUser() ? __('disposal.reported_by', ['user' => $d->reporter?->displayName() ?? '?']) : __('disposal.by_self') }}</span>
                        @if ($d->isBooked())
                            · {{ trans_choice('disposal.beers', (int) $d->quantity, ['count' => (int) $d->quantity]) }} · {{ $d->transaction !== null ? chf((int) $d->transaction->amount) : __('disposal.storno_note') }}
                        @elseif ($d->isReported())
                            · {{ __('disposal.booking_at', ['time' => local_time($d->bookingAt(), 'H:i')]) }}
                        @elseif ($d->withdrawn_by !== null)
                            · <span class="muted">{{ __('disposal.withdrawn_by', ['user' => $d->withdrawer?->displayName() ?? '?']) }}</span>
                        @else
                            · <span class="muted">{{ __('disposal.withdrawn_system') }}</span>
                        @endif
                        @if ($d->override_reason)
                            <br><span class="tx-note">{{ __('disposal.override_reason', ['reason' => $d->override_reason]) }}</span>
                        @endif
                    </span>
                    @if ($d->isWithdrawable($viewer))
                        <form method="post" action="{{ route('backend.disposals.withdraw', $d) }}" wire:ignore class="inline" data-confirm="{{ __('disposal.withdraw_confirm') }}">
                            @csrf
                            <button type="submit" class="btn small ghost">{{ __('disposal.withdraw') }}</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
        {{ $disposals->links('livewire.pagination') }}
    @endif
</section>
@endisland

@island('ranking', with: $__livewire->data())
<section class="card" data-testid="backend-disposal-ranking">
    <h2>{{ __('disposal.ranking_backend') }}</h2>
    @include('disposals._ranking', ['ranking' => $ranking])
    <p class="hint">{{ __('disposal.ranking_backend_hint', ['count' => $totals['count'], 'beers' => $totals['beers']]) }}</p>
</section>
@endisland
</div>

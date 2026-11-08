{{-- Offline emergency plan (1.40.0, admins; see App\Livewire\Backend\EmergencyPlan). Polls every 10 s while visible;
     only the island "status" is re-rendered, buttons and forms stay as rendered at mount. --}}
<div wire:poll.10s.visible="tick" data-testid="backend-emergency">
<section class="card">
    <h2>{{ __('emergency.status') }}</h2>
    @island('status', with: $__livewire->data())
    <div data-testid="emergency-status">
        <ul class="list">
            <li>
                <span>
                    <strong>{{ __('emergency.last_print') }}:</strong>
                    @if ($last)
                        {{ local_time($last->printed_at) }} · {{ $last->printer?->displayName() ?? '?' }}
                        · {{ __('emergency.users_on_list') }} {{ $last->user_count }}
                    @else
                        {{ __('emergency.never') }}
                    @endif
                </span>
            </li>
            <li>
                <span>
                    <strong>{{ __('emergency.difference') }}:</strong>
                    @if ($diff['count'] === 0)
                        {{ __('emergency.difference_none') }}
                    @else
                        @php
                            $newCount = $diff['new']->count();
                            $leftCount = $diff['count'] - $newCount;
                            $newText = trans_choice('emergency.new_users', $newCount, ['count' => $newCount])
                                .($newCount > 0 ? ': '.$diff['new']->map(fn ($u) => $u->displayName())->implode(', ') : '');
                            $leftNames = $diff['left']->map(fn ($u) => $u->displayName())->all();
                            if ($diff['left_unknown'] > 0) {
                                $leftNames[] = trans_choice('emergency.deleted_users', $diff['left_unknown'], ['count' => $diff['left_unknown']]);
                            }
                            $leftText = trans_choice('emergency.left_users', $leftCount, ['count' => $leftCount])
                                .($leftNames !== [] ? ': '.implode(', ', $leftNames) : '');
                        @endphp
                        <strong data-testid="emergency-difference">{{ $diff['count'] }}</strong>
                        ({{ $newText }} · {{ $leftText }})
                    @endif
                </span>
            </li>
        </ul>
        @if (! $last)
            <p class="hint">{{ __('emergency.never_hint') }}</p>
        @endif
        @if ($diff['count'] > $threshold)
            <div class="alert warn" role="alert" data-testid="emergency-over-threshold">{{ __('emergency.over_threshold', ['count' => $diff['count'], 'threshold' => $threshold]) }}</div>
        @endif
        @if ($mailSentAt)
            <p class="hint">{{ __('emergency.mail_sent_at', ['date' => local_time($mailSentAt)]) }}</p>
        @endif
    </div>
    @endisland
    <div class="row-buttons">
        <form method="post" action="{{ route('backend.emergency.print') }}" target="_blank" class="inline" data-testid="emergency-print">
            @csrf
            <button type="submit" class="btn primary">{{ __('emergency.print') }}</button>
        </form>
        <a class="btn" href="{{ route('backend.emergency.test') }}" target="_blank" rel="noopener" data-testid="emergency-test">{{ __('emergency.test_print') }}</a>
    </div>
    <p class="hint">{{ __('emergency.print_hint') }} {{ __('emergency.test_print_hint') }}</p>
</section>

<section class="card" id="fenster">
    <h2>{{ __('emergency.window_heading') }}</h2>
    <p class="hint">{{ __('emergency.window_intro') }}</p>
    <form method="post" action="{{ route('backend.emergency.window') }}" class="stack" data-testid="emergency-window">
        @csrf
        @method('PUT')
        <fieldset class="stack">
            <legend>{{ __('emergency.window_side') }}</legend>
            @foreach (\App\Support\AppSettings::EMERGENCY_WINDOW_SIDES as $side)
                <label class="check"><input type="radio" name="side" value="{{ $side }}" @checked(old('side', $window['side']) === $side)> <span>{{ __('emergency.window_sides.'.$side) }}</span></label>
            @endforeach
        </fieldset>
        @foreach (['top', 'edge', 'width', 'height'] as $key)
            <div class="field">
                <label for="window_{{ $key }}">{{ __('emergency.window_'.$key) }}</label>
                <input type="number" id="window_{{ $key }}" name="{{ $key }}" value="{{ old($key, number_format($window[$key] / 10, 1, '.', '')) }}" min="0" max="{{ in_array($key, ['top', 'height'], true) ? '29.7' : '21' }}" step="0.1" inputmode="decimal" required>
                @if ($key === 'edge')<div class="hint">{{ __('emergency.window_edge_hint') }}</div>@endif
                @if ($formErrors->has($key))<div class="hint neg">{{ $formErrors->first($key) }}</div>@endif
            </div>
        @endforeach
        <button type="submit" class="btn primary">{{ __('emergency.window_save') }}</button>
    </form>
</section>

<section class="card" id="texte">
    <h2>{{ __('emergency.texts_heading') }}</h2>
    <p class="hint">{{ __('emergency.texts_intro') }}</p>
    <form method="post" action="{{ route('backend.emergency.texts') }}" class="stack" data-testid="emergency-texts">
        @csrf
        @method('PUT')
        @foreach (\App\Support\AppSettings::EMERGENCY_TEXT_KEYS as $key)
            <div class="field">
                <label for="text_{{ $key }}">{{ __('emergency.text.'.$key) }} @if (in_array($key, $customized, true))<span class="pill">{{ __('emergency.text_custom') }}</span>@endif</label>
                @if (in_array($key, ['steps', 'short_guide', 'footer', 'contact'], true))
                    <textarea id="text_{{ $key }}" name="texts[{{ $key }}]" rows="{{ $key === 'steps' ? 5 : 3 }}" maxlength="{{ \App\Support\AppSettings::EMERGENCY_TEXT_MAX[$key] }}" required>{{ old('texts.'.$key, $texts[$key]) }}</textarea>
                @else
                    <input type="text" id="text_{{ $key }}" name="texts[{{ $key }}]" value="{{ old('texts.'.$key, $texts[$key]) }}" maxlength="{{ \App\Support\AppSettings::EMERGENCY_TEXT_MAX[$key] }}" required>
                @endif
                @if ($formErrors->has('texts.'.$key))<div class="hint neg">{{ $formErrors->first('texts.'.$key) }}</div>@endif
                @if (in_array($key, $customized, true))
                    <div><button type="submit" form="emergency-reset-{{ $key }}" class="btn small ghost">{{ __('emergency.text_reset_button') }}</button></div>
                @endif
            </div>
        @endforeach
        <button type="submit" class="btn primary">{{ __('emergency.texts_save') }}</button>
    </form>
    {{-- One form per text for "Standardtext wiederherstellen" (forms cannot be nested; the buttons use form="…"). --}}
    @foreach ($customized as $key)
        <form method="post" action="{{ route('backend.emergency.texts.reset', $key) }}" id="emergency-reset-{{ $key }}" hidden>
            @csrf
            @method('DELETE')
        </form>
    @endforeach
</section>

<section class="card" id="mail">
    <h2>{{ __('emergency.mail_heading') }}</h2>
    <p class="hint">{{ __('emergency.mail_intro') }}</p>
    <form method="post" action="{{ route('backend.emergency.mail') }}" class="stack" data-testid="emergency-mail">
        @csrf
        @method('PUT')
        <div class="field">
            <label for="emergency_threshold">{{ __('emergency.mail_threshold') }}</label>
            <input type="number" id="emergency_threshold" name="threshold" value="{{ old('threshold', $threshold) }}" min="0" max="{{ \App\Support\AppSettings::EMERGENCY_MAIL_THRESHOLD_MAX }}" step="1" inputmode="numeric" required>
            @if ($formErrors->has('threshold'))<div class="hint neg">{{ $formErrors->first('threshold') }}</div>@endif
        </div>
        <fieldset class="stack">
            <legend>{{ __('emergency.mail_recipients') }}</legend>
            @php($chosen = array_map('intval', (array) old('recipients', $mailRecipients)))
            @foreach ($candidates as $c)
                <label class="check"><input type="checkbox" name="recipients[]" value="{{ $c->id }}" @checked(in_array($c->id, $chosen, true))> <span>{{ $c->displayName() }} <small class="muted">({{ __('users.roles.'.$c->role) }}@if (! $c->email) · {{ __('emergency.mail_no_address') }}@endif)</small></span></label>
            @endforeach
            @if ($formErrors->has('recipients') || $formErrors->has('recipients.*'))<div class="hint neg">{{ $formErrors->first('recipients') ?: $formErrors->first('recipients.*') }}</div>@endif
            @if ($mailRecipients === [])
                <p class="hint">{{ __('emergency.mail_recipients_none') }}</p>
            @endif
        </fieldset>
        <button type="submit" class="btn primary">{{ __('emergency.mail_save') }}</button>
    </form>
</section>
</div>

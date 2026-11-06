{{-- TWINT message ("Mitteilung") for $username with copy button; tapping the text selects it (manual copy).
     Optional $hintKey (1.33.0): collapsible hint (account dashboard); without it the plain hint (guest views). --}}
<div class="twint-message">
    <span class="label">{{ __('account.twint_message') }}</span>
    <p class="twint-message-text" id="twint-message" data-select-all>{{ \App\Support\AppSettings::twintMessage($username) }}</p>
    <div class="row-buttons">
        <button type="button" class="btn small" data-copy="#twint-message" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button>
        @if ($hintKey ?? null)
            <x-hint :key="$hintKey">{{ __('account.twint_message_hint') }}</x-hint>
        @else
            <span class="hint">{{ __('account.twint_message_hint') }}</span>
        @endif
    </div>
</div>

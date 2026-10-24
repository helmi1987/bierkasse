{{-- TWINT message ("Mitteilung") for $username with copy button; tapping the text selects it (manual copy). --}}
<div class="twint-message">
    <span class="label">{{ __('account.twint_message') }}</span>
    <p class="twint-message-text" id="twint-message" data-select-all>{{ \App\Support\AppSettings::twintMessage($username) }}</p>
    <div class="row-buttons">
        <button type="button" class="btn small" data-copy="#twint-message" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button>
        <span class="hint">{{ __('account.twint_message_hint') }}</span>
    </div>
</div>

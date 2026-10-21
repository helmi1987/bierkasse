{{-- TWINT recipient: number with copy button, or only the name when no number is configured. --}}
<div class="twint-number">
    @isset($label)<span class="muted">{{ $label }}</span>@endisset
    @if ($twintPhone !== '')
        <span id="{{ $id }}">{{ $twintPhone }}</span>
        <button type="button" class="btn small" data-copy="#{{ $id }}" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button>
    @else
        <span>{{ $twintName }}</span>
    @endif
</div>

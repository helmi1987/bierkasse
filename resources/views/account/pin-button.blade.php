{{-- Pin/unpin a colleague; belongs to the shared form #favorites-form (public/js/app.js toggles via fetch). --}}
@php($name = $colleague->displayName())
<button type="submit" class="pin-btn" form="favorites-form" formaction="{{ route('account.favorite', $colleague) }}" data-pin="{{ $colleague->id }}"
    data-label-pin="{{ __('round.pin', ['name' => $name]) }}" data-label-unpin="{{ __('round.unpin', ['name' => $name]) }}"
    aria-pressed="{{ $colleague->is_favorite ? 'true' : 'false' }}" aria-label="{{ __($colleague->is_favorite ? 'round.unpin' : 'round.pin', ['name' => $name]) }}" title="{{ __($colleague->is_favorite ? 'round.unpin' : 'round.pin', ['name' => $name]) }}">{{ $colleague->is_favorite ? '★' : '☆' }}</button>

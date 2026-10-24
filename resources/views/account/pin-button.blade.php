{{-- Pin/unpin a colleague in list $list ('round'|'gift'); belongs to the shared form #favorites-form (public/js/app.js toggles via fetch). --}}
@php($name = $colleague->displayName())
<button type="submit" class="pin-btn" form="favorites-form" formaction="{{ route('account.favorite', ['list' => $list, 'colleague' => $colleague->id]) }}" data-pin="{{ $colleague->id }}" data-list="{{ $list }}"
    data-label-pin="{{ __('round.pin', ['name' => $name]) }}" data-label-unpin="{{ __('round.unpin', ['name' => $name]) }}"
    aria-pressed="{{ $colleague->is_favorite ? 'true' : 'false' }}" aria-label="{{ __($colleague->is_favorite ? 'round.unpin' : 'round.pin', ['name' => $name]) }}" title="{{ __($colleague->is_favorite ? 'round.unpin' : 'round.pin', ['name' => $name]) }}">{{ $colleague->is_favorite ? '★' : '☆' }}</button>

@props(['key'])
{{-- Help text (1.33.1): hidden block below a title or field label, opened by the <x-hint-toggle> with the same key.
     Not hidden when the logged-in user opened the key before; shown without JavaScript (html:not(.js) in app.css). --}}
@php
    \App\Support\Hints::assertKey($key);
    $hintOpen = \App\Support\Hints::isOpen(auth()->user(), $key);
@endphp
<div {{ $attributes->class(['hint', 'hint-text']) }} id="{{ \App\Support\Hints::domId($key) }}" data-hint="{{ $key }}"{{ $hintOpen ? '' : ' hidden' }}>{{ $slot }}</div>
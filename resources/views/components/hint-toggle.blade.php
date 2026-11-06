@props(['key'])
{{-- Help toggle (1.33.1): blue info button at the end of a title or field label; opens the <x-hint> text with the
     same key (aria-controls). Open keys are remembered per account (App\Support\Hints, saved by app.js on click).
     Hidden without JavaScript, the texts are shown then. --}}
@php
    \App\Support\Hints::assertKey($key);
    $hintOpen = \App\Support\Hints::isOpen(auth()->user(), $key);
@endphp
<button type="button" class="hint-toggle" data-hint-toggle="{{ $key }}" aria-controls="{{ \App\Support\Hints::domId($key) }}" aria-expanded="{{ $hintOpen ? 'true' : 'false' }}" aria-label="{{ __('account.help_show') }}" title="{{ __('account.help_show') }}"><span class="hint-icon" aria-hidden="true">i</span></button>
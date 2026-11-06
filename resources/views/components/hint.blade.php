@props(['key'])
{{-- Collapsible help text (1.33.0): closed by default, the info toggle opens it; open keys are remembered per
     account (App\Support\Hints, saved by app.js on toggle). Works without JavaScript (plain <details>). --}}
@php
    \App\Support\Hints::assertKey($key);
    $hintId = \App\Support\Hints::domId($key);
    $hintOpen = \App\Support\Hints::isOpen(auth()->user(), $key);
@endphp
<details {{ $attributes->class(['hint-box']) }} data-hint="{{ $key }}"{{ $hintOpen ? ' open' : '' }}>
    <summary class="hint-toggle" aria-label="{{ __('account.help_show') }}" aria-controls="{{ $hintId }}" aria-expanded="{{ $hintOpen ? 'true' : 'false' }}" title="{{ __('account.help_show') }}"><span class="hint-icon" aria-hidden="true">i</span></summary>
    <div class="hint" id="{{ $hintId }}">{{ $slot }}</div>
</details>

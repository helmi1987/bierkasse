{{-- Tabs of a Livewire component: $tabs = [key => label], $active, $route (+ $routeParams). Links work without JS. --}}
<nav class="tabs" aria-label="{{ $label ?? '' }}">
    @foreach ($tabs as $key => $text)
        <a href="{{ route($route, ($routeParams ?? []) + ($key === array_key_first($tabs) ? [] : ['tab' => $key])) }}" wire:click.prevent="showTab('{{ $key }}')" @class(['active' => $active === $key]) @if ($active === $key) aria-current="page" @endif>{{ $text }}</a>
    @endforeach
</nav>

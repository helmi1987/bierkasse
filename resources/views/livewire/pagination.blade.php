{{-- Pagination inside Livewire components (same look as partials.pagination; buttons, no page reload). --}}
<div>
    @if ($paginator->hasPages())
        <nav class="pager" aria-label="Seiten">
            @if ($paginator->onFirstPage())
                <span class="btn ghost disabled">{{ __('site.previous') }}</span>
            @else
                <button type="button" class="btn ghost" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" rel="prev">{{ __('site.previous') }}</button>
            @endif
            <span class="muted">{{ __('site.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
            @if ($paginator->hasMorePages())
                <button type="button" class="btn ghost" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" rel="next">{{ __('site.next') }}</button>
            @else
                <span class="btn ghost disabled">{{ __('site.next') }}</span>
            @endif
        </nav>
    @endif
</div>

@if ($paginator->hasPages())
    <nav class="pager" aria-label="Seiten">
        @if ($paginator->onFirstPage())
            <span class="btn ghost disabled">{{ __('site.previous') }}</span>
        @else
            <a class="btn ghost" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('site.previous') }}</a>
        @endif
        <span class="muted">{{ __('site.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>
        @if ($paginator->hasMorePages())
            <a class="btn ghost" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('site.next') }}</a>
        @else
            <span class="btn ghost disabled">{{ __('site.next') }}</span>
        @endif
    </nav>
@endif

{{-- Tabs of "Lager": stock and disposal bonus ($stockTab = 'stock'|'entsorgung'), like "Kasse" (1.40.2). --}}
<nav class="tabs" aria-label="{{ __('stock.title') }}">
    <a href="{{ route('backend.stock') }}" @class(['active' => $stockTab === 'stock'])>{{ __('backend.tabs.stock') }}</a>
    <a href="{{ route('backend.stock', ['tab' => 'entsorgung']) }}" @class(['active' => $stockTab === 'entsorgung'])>{{ __('backend.tabs.disposals') }}</a>
</nav>

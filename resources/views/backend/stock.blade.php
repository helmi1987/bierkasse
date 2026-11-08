{{--
    Beer stock (App\Livewire\Backend\Stock) and, since 1.39.0, the "Entsorgungsbonus" below it
    (App\Livewire\Backend\Disposals, anchor #entsorgung; the old page /admin/entsorgung redirects here).
--}}
@extends('layouts.app')

@section('title', __('stock.title'))
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<h1>{{ __('stock.title') }}</h1>
<p class="muted">{{ __('stock.intro') }}</p>
<livewire:backend.stock />

<section id="entsorgung" class="page-section" data-testid="stock-disposals">
    <h2>{{ __('disposal.title') }}</h2>
    <p class="muted">{{ __('disposal.intro') }}</p>
    <livewire:backend.disposals />
</section>
@endsection

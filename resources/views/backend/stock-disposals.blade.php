{{-- Tab "Entsorgung" of "Lager" (1.40.2): App\Livewire\Backend\Disposals; its forms post to DisposalController. --}}
@extends('layouts.app')

@section('title', __('disposal.title'))
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<h1>{{ __('stock.title') }}</h1>
@include('backend.partials.stock-tabs', ['stockTab' => 'entsorgung'])
<div data-testid="stock-disposals">
<p class="muted">{{ __('disposal.intro') }}</p>
<livewire:backend.disposals />
</div>
@endsection

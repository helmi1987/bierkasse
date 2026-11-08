{{--
    Beer stock (App\Livewire\Backend\Stock), tab "Bestand". The "Entsorgungsbonus" is the tab "Entsorgung"
    (?tab=entsorgung, view backend/stock-disposals, since 1.40.2; 1.39.0 had it as an area below the stock).
--}}
@extends('layouts.app')

@section('title', __('stock.title'))
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<h1>{{ __('stock.title') }}</h1>
@include('backend.partials.stock-tabs', ['stockTab' => 'stock'])
<p class="muted">{{ __('stock.intro') }}</p>
<livewire:backend.stock />
@endsection

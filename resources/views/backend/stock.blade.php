@extends('layouts.app')

@section('title', __('stock.title'))
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<h1>{{ __('stock.title') }}</h1>
<p class="muted">{{ __('stock.intro') }}</p>
<livewire:backend.stock />
@endsection

@extends('layouts.app')

@section('title', __('cash.title'))
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<h1>{{ __('cash.title') }}</h1>
@include('backend.partials.cash-tabs', ['cashTab' => 'book'])
<p class="muted">{{ __('cash.intro') }}</p>
<livewire:backend.cash />
@endsection

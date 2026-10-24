@extends('layouts.app')

@section('title', __('deposit_suggestions.title'))
@section('livewire', '1')

@section('content')
@include('partials.backend-nav')
<h1>{{ __('cash.title') }}</h1>
@include('backend.partials.cash-tabs', ['cashTab' => 'vorschlaege'])
<livewire:backend.deposit-suggestions-form />
@endsection

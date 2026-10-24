@extends('layouts.app')

@section('title', __('backend.bookings_title'))
@section('livewire', '1')

@section('content')
@include('partials.backend-nav')
<h1>{{ __('backend.bookings_title') }}</h1>
<p class="muted">{{ __('backend.bookings_intro') }}</p>
<livewire:backend.bookings />
@endsection

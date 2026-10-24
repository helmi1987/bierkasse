@extends('layouts.app')

@section('title', __('qr.title'))
@section('livewire', '1')

@section('content')
@include('partials.backend-nav')
<h1>{{ __('qr.title') }}</h1>
<livewire:backend.qr-codes />
@endsection

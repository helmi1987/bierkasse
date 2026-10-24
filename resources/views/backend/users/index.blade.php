@extends('layouts.app')

@section('title', __('users.title'))
@section('livewire', '1')

@section('content')
@include('partials.backend-nav')
<h1>{{ __('users.title') }}</h1>
<livewire:backend.users />
@endsection

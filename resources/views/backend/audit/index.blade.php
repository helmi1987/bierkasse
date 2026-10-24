@extends('layouts.app')

@section('title', __('audit.title'))
@section('livewire', '1')

@section('content')
@include('partials.backend-nav')
<h1>{{ __('audit.title') }}</h1>
<livewire:backend.audit-log-list />
@endsection

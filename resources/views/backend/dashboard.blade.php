@extends('layouts.app')

@section('title', __('backend.title'))
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<h1>{{ __('backend.title') }}</h1>
<livewire:backend.dashboard />
@endsection

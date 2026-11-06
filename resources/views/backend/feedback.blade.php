@extends('layouts.app')

@section('title', __('feedback.backend_title'))
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<h1>{{ __('feedback.backend_title') }}</h1>
<livewire:backend.feedback-list />
@endsection

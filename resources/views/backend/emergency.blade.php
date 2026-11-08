{{-- Offline emergency plan (1.40.0, admins only): Livewire component App\Livewire\Backend\EmergencyPlan; the forms
     post to App\Http\Controllers\Backend\EmergencyPlanController. --}}
@extends('layouts.app')

@section('title', __('emergency.title'))
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<h1>{{ __('emergency.title') }}</h1>
<p class="muted">{{ __('emergency.intro') }}</p>
<livewire:backend.emergency-plan />
@endsection

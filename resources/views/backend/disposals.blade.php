{{--
    "Entsorgungsbonus" (1.32.0, kassiers and admins): guide, settings, status, report for a user, the list and the ranking.
    Plain forms posting to App\Http\Controllers\Backend\DisposalController; the page is the Livewire component
    App\Livewire\Backend\Disposals (1.36.0, live status, list and ranking).
--}}
@extends('layouts.app')

@section('title', __('disposal.title'))
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<h1>{{ __('disposal.title') }}</h1>
<p class="muted">{{ __('disposal.intro') }}</p>
<livewire:backend.disposals />
@endsection

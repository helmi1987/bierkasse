{{--
    Backend statistics (1.31.0, kassiers and admins): filters, KPIs with the preceding period, table by time or by
    drinker, chart and CSV export. Plain GET links and forms; the page is the Livewire component
    App\Livewire\Backend\Stats (1.36.0). See App\Http\Controllers\Backend\StatsController.
--}}
@extends('layouts.app')

@section('title', __('stats.backend.title'))
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<h1>{{ __('stats.backend.title') }}</h1>
<p class="muted">{{ __('stats.backend.intro') }}</p>
{{-- Period and filters from the query; only plain strings (?typ[]=x is ignored like before). --}}
@php($statsQuery = fn (string $key) => is_string(request()->query($key)) ? request()->query($key) : null)
<livewire:backend.stats :zeitraum="$statsQuery('zeitraum')" :von="$statsQuery('von')" :bis="$statsQuery('bis')"
    :benutzer="$statsQuery('benutzer')" :typ="$statsQuery('typ')" :gruppe="$statsQuery('gruppe')" :raster="$statsQuery('raster')" />
@endsection

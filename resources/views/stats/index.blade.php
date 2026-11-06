@extends('layouts.app')

@section('title', __('stats.title'))
@section('livewire', '1')

@section('content')
{{-- Period from the query; only plain strings (?zeitraum[]=x is ignored like before). --}}
@php($periodQuery = fn (string $key) => is_string(request()->query($key)) ? request()->query($key) : null)
<livewire:stats :zeitraum="$periodQuery('zeitraum')" :von="$periodQuery('von')" :bis="$periodQuery('bis')" />
@endsection

@extends('layouts.app')

@section('title', $user->displayName())
@section('livewire', '1')

@section('content')
<livewire:backend.sub-nav />
<livewire:backend.user-show :user="$user" />
@endsection

@extends('layouts.app')

@section('title', __('account.history_title'))

@section('content')
<h1>{{ __('account.history_title') }}</h1>
<p class="lead">{{ __('account.balance') }}: <strong>{{ chf($balance) }}</strong></p>
<section class="card">
    @include('account.tx-list', ['transactions' => $transactions])
    {{ $transactions->links('partials.pagination') }}
</section>
@endsection

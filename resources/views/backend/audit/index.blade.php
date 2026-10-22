@extends('layouts.app')

@section('title', __('audit.title'))

@section('content')
{{-- Kassier bar or admin bar (same page). --}}
@php($auditBase = request()->routeIs('backend.audit-admin*') ? 'backend.audit-admin' : 'backend.audit')
@include('partials.backend-nav')
<h1>{{ __('audit.title') }}</h1>
<p class="muted">{{ __('audit.intro') }}</p>

<section class="card">
    <form method="get" action="{{ route($auditBase) }}" class="inline-form">
        <label for="f-actor">{{ __('audit.filter_actor') }}</label>
        <select id="f-actor" name="actor">
            <option value="">{{ __('audit.filter_actor_all') }}</option>
            @foreach ($actors as $a)
                <option value="{{ $a->actor_ref }}" @selected($filters['actor'] === (int) $a->actor_ref)>{{ $a->actor_name }}</option>
            @endforeach
        </select>
        <label for="f-action">{{ __('audit.filter_action') }}</label>
        <select id="f-action" name="action">
            <option value="">{{ __('audit.filter_action_all') }}</option>
            @foreach ($prefixes as $p)
                <option value="{{ $p }}" @selected($filters['action'] === $p)>{{ $p }}</option>
            @endforeach
        </select>
        <label for="f-from">{{ __('audit.filter_from') }}</label>
        <input type="date" id="f-from" name="from" value="{{ $filters['from'] }}">
        <label for="f-to">{{ __('audit.filter_to') }}</label>
        <input type="date" id="f-to" name="to" value="{{ $filters['to'] }}">
        <label for="f-q">{{ __('audit.filter_q') }}</label>
        <input type="search" id="f-q" name="q" value="{{ $filters['q'] }}" maxlength="100">
        <button type="submit" class="btn small">{{ __('audit.filter_submit') }}</button>
        <a class="btn small ghost" href="{{ route($auditBase) }}">{{ __('audit.filter_reset') }}</a>
    </form>
    <div class="row-buttons">
        <form method="post" action="{{ route('backend.audit.verify') }}" class="inline">
            @csrf
            <button type="submit" class="btn small">{{ __('audit.verify') }}</button>
        </form>
        <a class="btn small ghost" href="{{ route('backend.audit.export', array_filter($filters, fn ($v) => $v !== null && $v !== '')) }}">{{ __('audit.export') }}</a>
    </div>
</section>

<section class="card">
    @if ($entries->isEmpty())
        <p class="muted">{{ __('audit.none') }}</p>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('audit.time') }}</th>
                        <th>{{ __('audit.actor') }}</th>
                        <th>{{ __('audit.action') }}</th>
                        <th>{{ __('audit.subject') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td><a href="{{ route($auditBase.'.show', $entry) }}">{{ local_time($entry->at(), 'd.m.Y H:i:s') }}</a></td>
                            <td>{{ $entry->actor_name ?? __('audit.system') }}</td>
                            <td><code>{{ $entry->action }}</code></td>
                            <td>@if ($entry->subject_type){{ $entry->subject_type }} #{{ $entry->subject_id }}@else – @endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $entries->links('partials.pagination') }}
    @endif
</section>
@endsection

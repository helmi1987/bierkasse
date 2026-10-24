@extends('layouts.app')

@section('title', __('audit.entry', ['id' => $entry->id]))

@section('content')
{{-- Kassier bar or admin bar (same page). --}}
@php($auditBase = request()->routeIs('backend.audit-admin*') ? 'backend.audit-admin' : 'backend.audit')
@include('partials.backend-nav')
<h1>{{ __('audit.entry', ['id' => $entry->id]) }}: {{ \App\Support\AuditPresenter::actionLabel($entry->action) }} <small class="muted"><code>{{ $entry->action }}</code></small></h1>
<p class="muted">{{ local_time($entry->at(), 'd.m.Y H:i:s') }} · {{ $entry->actor_name ?? __('audit.system') }}@if ($entry->subject_type) · {{ $presenter->subjectLabel($entry) }} <small><code>{{ $entry->subject_type }} #{{ $entry->subject_id }}</code></small>@endif</p>

@php($old = $entry->oldValues())
@php($new = $entry->newValues())
@php($keys = array_unique(array_merge(array_keys($old), array_keys($new))))
<section class="card">
    @if ($keys === [])
        <p class="muted">{{ __('audit.no_values') }}</p>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>{{ __('audit.field') }}</th><th>{{ __('audit.old') }}</th><th>{{ __('audit.new') }}</th></tr>
                </thead>
                <tbody>
                    @foreach ($keys as $key)
                        <tr>
                            <td>
                                @if (\App\Support\AuditPresenter::hasFieldLabel($key))
                                    {{ \App\Support\AuditPresenter::fieldLabel($key) }} <small class="muted"><code>{{ $key }}</code></small>
                                @else
                                    <code>{{ $key }}</code>
                                @endif
                            </td>
                            <td>{{ array_key_exists($key, $old) ? $presenter->value($key, $old[$key]) : '' }}</td>
                            <td>{{ array_key_exists($key, $new) ? $presenter->value($key, $new[$key]) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section class="card">
    <dl class="facts">
        <dt>{{ __('audit.ip') }}</dt><dd>{{ $entry->ip ?? '–' }}</dd>
        <dt>{{ __('audit.user_agent') }}</dt><dd>{{ $entry->user_agent ?? '–' }}</dd>
        <dt>{{ __('audit.prev_hash') }}</dt><dd><code>{{ $entry->prev_hash ?: '–' }}</code></dd>
        <dt>{{ __('audit.hash') }}</dt><dd><code>{{ $entry->hash }}</code></dd>
    </dl>
</section>

<p><a href="{{ route($auditBase) }}">← {{ __('audit.back') }}</a></p>
@endsection

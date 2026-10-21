@extends('layouts.app')

@section('title', __('audit.entry', ['id' => $entry->id]))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('audit.entry', ['id' => $entry->id]) }} <code>{{ $entry->action }}</code></h1>
<p class="muted">{{ local_time($entry->at(), 'd.m.Y H:i:s') }} · {{ $entry->actor_name ?? __('audit.system') }}@if ($entry->subject_type) · {{ $entry->subject_type }} #{{ $entry->subject_id }}@endif</p>

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
                            <td><code>{{ $key }}</code></td>
                            <td>{{ array_key_exists($key, $old) ? json_encode($old[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}</td>
                            <td>{{ array_key_exists($key, $new) ? json_encode($new[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}</td>
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

<p><a href="{{ route('backend.audit') }}">← {{ __('audit.back') }}</a></p>
@endsection

@extends('layouts.app')

@section('title', __('legal.revisions_title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('legal.revisions_title') }}</h1>
<p class="muted">{{ __('legal.revisions_intro') }}</p>

<section class="card">
    <form method="get" action="{{ route('backend.legal-revisions') }}" class="inline-form">
        <label for="f-kind">{{ __('legal.revisions_kind') }}</label>
        <select id="f-kind" name="kind">
            <option value="">{{ __('legal.revisions_all') }}</option>
            @foreach (\App\Support\LegalTexts::KINDS as $k)
                <option value="{{ $k }}" @selected($kind === $k)>{{ __('legal.title.'.$k) }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn small">{{ __('audit.filter_submit') }}</button>
    </form>
</section>

<section class="card">
    @if ($revisions->isEmpty())
        <p class="muted">{{ __('legal.revisions_none') }}</p>
    @else
        <div class="table-wrap">
            <table class="table" data-testid="legal-revisions">
                <thead>
                    <tr>
                        <th>{{ __('legal.revisions_date') }}</th>
                        <th>{{ __('legal.revisions_kind') }}</th>
                        <th>{{ __('legal.revisions_version') }}</th>
                        <th>{{ __('legal.revisions_reason') }}</th>
                        <th>{{ __('legal.revisions_by') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($revisions as $revision)
                        <tr>
                            <td><a href="{{ route('legal.revision', $revision) }}">{{ $revision->label() }}</a></td>
                            <td>{{ __('legal.title.'.$revision->kind) }}</td>
                            <td>{{ \App\Support\LegalTexts::versionDate($revision->version) }}</td>
                            <td>{{ __('legal.reason.'.$revision->reason) }}</td>
                            <td>{{ $revision->creator?->username ?? __('legal.revisions_system') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $revisions->links('partials.pagination') }}
    @endif
</section>
@endsection

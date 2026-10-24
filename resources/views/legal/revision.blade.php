@extends('layouts.app')

@section('title', $title)

@section('content')
<article class="card legal-text" data-testid="legal-revision-{{ $revision->id }}">
    <p class="muted">{{ __('legal.revision_header', ['date' => $revision->label(), 'version' => \App\Support\LegalTexts::versionDate($revision->version)]) }}</p>
    <h1>{{ $title }}</h1>
    {{ $html }}
</article>
<p class="center no-print"><a href="{{ auth()->user()->homeUrl() }}">{{ __('legal.back') }}</a></p>
@endsection

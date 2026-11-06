@extends('layouts.app')

@section('title', __('feedback.title'))
@section('main_class', 'narrow')

@section('content')
<h1>{{ __('feedback.title') }}</h1>

<section class="card">
    <p>{{ __('feedback.intro') }}</p>
    <form method="post" action="{{ route('account.feedback.store') }}" class="stack">
        @csrf
        {!! \App\Support\FormToken::field('feedback') !!}
        @if ($page)
            <input type="hidden" name="page" value="{{ old('page', $page) }}">
        @endif
        <fieldset class="segmented">
            <legend>{{ __('feedback.type') }}</legend>
            @foreach ($types as $type)
                <label>
                    <input type="radio" name="type" value="{{ $type }}" @checked(old('type', \App\Models\Feedback::TYPE_FEEDBACK) === $type)>
                    <span>{{ __('feedback.types.'.$type) }}</span>
                </label>
            @endforeach
        </fieldset>
        <div class="field">
            <label for="message">{{ __('feedback.message') }}</label>
            <textarea id="message" name="message" rows="6" minlength="{{ \App\Models\Feedback::MESSAGE_MIN }}" maxlength="{{ \App\Models\Feedback::MESSAGE_MAX }}" required>{{ old('message') }}</textarea>
            <div class="hint">{{ __('feedback.message_hint', ['min' => \App\Models\Feedback::MESSAGE_MIN, 'max' => \App\Models\Feedback::MESSAGE_MAX]) }}</div>
        </div>
        @if ($hasEmail)
            <label class="check"><input type="checkbox" name="wants_reply" value="1" @checked(old('wants_reply'))> <span>{{ __('feedback.wants_reply') }}</span></label>
            <p class="hint">{{ __('feedback.wants_reply_hint', ['email' => auth()->user()->email]) }}</p>
        @else
            <p class="hint">{{ __('feedback.no_email_hint') }}</p>
        @endif
        <button type="submit" class="btn primary big">{{ __('feedback.submit') }}</button>
    </form>
</section>
@endsection

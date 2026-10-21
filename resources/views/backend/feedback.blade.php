@extends('layouts.app')

@section('title', __('feedback.backend_title'))

@section('content')
@include('partials.backend-nav')
<h1>{{ __('feedback.backend_title') }}</h1>

<section class="card">
    <p class="muted">{{ __('feedback.unread_count', ['count' => $unread]) }}</p>
    @if ($feedback->isEmpty())
        <p class="muted">{{ __('feedback.none') }}</p>
    @else
        <ul class="list">
            @foreach ($feedback as $item)
                <li class="wrap-row">
                    <span>
                        <span class="tx-date">{{ local_time($item->created_at) }}</span>
                        <span class="pill">{{ __('feedback.types.'.$item->type) }}</span>
                        @if ($item->read_at === null)
                            <span class="pill warn">{{ __('feedback.unread') }}</span>
                        @endif
                        @if ($item->wants_reply)
                            <span class="pill ok">{{ __('feedback.wants_reply_pill') }}</span>
                        @endif
                        <br>
                        @if ($item->user)
                            {{ __('feedback.from', ['user' => $item->user->username]) }}@if ($item->user->displayName() !== $item->user->username) ({{ $item->user->displayName() }})@endif
                            @if ($item->user->email)
                                · <a href="mailto:{{ $item->user->email }}">{{ $item->user->email }}</a>
                            @endif
                        @else
                            {{ __('feedback.from', ['user' => __('feedback.deleted_user')]) }}
                        @endif
                        <span class="feedback-message">{{ $item->message }}</span>
                        @if ($item->page)
                            <small class="muted">{{ __('feedback.page', ['page' => $item->page]) }}</small>
                        @endif
                    </span>
                    <span class="row-buttons feedback-actions">
                        <form method="post" action="{{ route('backend.feedback.read', $item) }}" class="inline">
                            @csrf
                            <input type="hidden" name="read" value="{{ $item->read_at === null ? 1 : 0 }}">
                            <button type="submit" class="btn small">{{ $item->read_at === null ? __('feedback.mark_read') : __('feedback.mark_unread') }}</button>
                        </form>
                        <form method="post" action="{{ route('backend.feedback.destroy', $item) }}" class="inline" data-confirm="{{ __('feedback.delete_confirm') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn small ghost">{{ __('feedback.delete') }}</button>
                        </form>
                    </span>
                </li>
            @endforeach
        </ul>
        {{ $feedback->links('partials.pagination') }}
    @endif
</section>

<section class="card">
    <h2>{{ __('feedback.recipients_title') }}</h2>
    <form method="post" action="{{ route('backend.feedback.recipients') }}" class="stack">
        @csrf
        @method('PUT')
        @foreach (\App\Support\AppSettings::FEEDBACK_RECIPIENT_MODES as $mode)
            <label class="check"><input type="radio" name="recipient_mode" value="{{ $mode }}" @checked(old('recipient_mode', $recipientMode) === $mode)> <span>{{ __('feedback.recipient_modes.'.$mode) }}</span></label>
        @endforeach
        <div class="field">
            <label for="recipients">{{ __('feedback.recipients') }}</label>
            <textarea id="recipients" name="recipients" rows="3" maxlength="5000">{{ old('recipients', implode("\n", $recipients)) }}</textarea>
            <div class="hint">{{ __('feedback.recipients_hint') }}</div>
        </div>
        <p class="hint">{{ __('feedback.recipients_note') }}</p>
        @if ($effectiveRecipients === [])
            <div class="alert warn">{{ __('feedback.recipients_current_none') }}</div>
        @else
            <p class="muted">{{ __('feedback.recipients_current', ['list' => implode(', ', $effectiveRecipients)]) }}</p>
        @endif
        <button type="submit" class="btn primary">{{ __('feedback.recipients_save') }}</button>
    </form>
</section>
@endsection

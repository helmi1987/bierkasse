{{-- Admin: deactivated QR codes ($history) with reactivation; $kindLabel(fn) optional. --}}
<details wire:ignore.self>
    <summary>{{ __('qr.history') }} ({{ $history->count() }})</summary>
    @if ($history->isEmpty())
        <p class="muted">{{ __('qr.history_none') }}</p>
    @else
        <ul class="list">
            @foreach ($history as $code)
                <li class="wrap-row">
                    <span>
                        <strong>{{ isset($kindLabel) ? $kindLabel($code).' · ' : '' }}{{ $code->displayName() }}</strong><br>
                        <small class="muted">{{ __('qr.meta', ['date' => local_time($code->created_at), 'user' => $code->creator?->username ?? '–']) }} · {{ __('qr.meta_deactivated', ['date' => local_time($code->deactivated_at)]) }}</small>
                    </span>
                    <form method="post" action="{{ route('backend.qr-codes.active', $code) }}" class="inline">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="active" value="1">
                        <button type="submit" class="btn small">{{ __('qr.activate') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</details>

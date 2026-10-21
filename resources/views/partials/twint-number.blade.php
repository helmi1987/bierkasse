{{-- TWINT recipients: number with copy button and name, or only the name when no number is configured. --}}
@foreach ($twintRecipients as $i => $r)
    <div class="twint-number">
        @if ($loop->first && isset($label))<span class="muted">{{ $label }}</span>@endif
        @if ($r['phone'] !== '')
            <span id="{{ $id }}-{{ $i }}">{{ $r['phone'] }}</span>
            <button type="button" class="btn small" data-copy="#{{ $id }}-{{ $i }}" data-copied="{{ __('account.copied') }}">{{ __('account.copy') }}</button>
            @if ($r['name'] !== '')<small class="muted">{{ $r['name'] }}</small>@endif
        @else
            <span>{{ $r['name'] }}</span>
        @endif
    </div>
@endforeach

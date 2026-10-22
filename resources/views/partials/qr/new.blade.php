{{-- Admin: new QR code of $kind with optional name; $button = button text. --}}
<form method="post" action="{{ route('backend.qr-codes.store') }}" class="inline-form">
    @csrf
    <input type="hidden" name="kind" value="{{ $kind }}">
    <label class="sr-only" for="qr-new-{{ $kind }}">{{ __('qr.label') }}</label>
    <input type="text" id="qr-new-{{ $kind }}" name="label" maxlength="100" placeholder="{{ __('qr.label_placeholder') }}">
    <button type="submit" class="btn small">{{ $button }}</button>
</form>

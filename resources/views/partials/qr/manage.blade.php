{{-- Admin: rename and deactivate an active QR code ($code). --}}
<form method="post" action="{{ route('backend.qr-codes.label', $code) }}" class="inline-form">
    @csrf
    @method('PUT')
    <label class="sr-only" for="qr-label-{{ $code->id }}">{{ __('qr.label') }}</label>
    <input type="text" id="qr-label-{{ $code->id }}" name="label" value="{{ $code->label }}" maxlength="100" placeholder="{{ __('qr.label_placeholder') }}">
    <button type="submit" class="btn small ghost">{{ __('qr.label_save') }}</button>
</form>
<form method="post" action="{{ route('backend.qr-codes.active', $code) }}" class="inline" data-confirm="{{ __('qr.deactivate_confirm') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="active" value="0">
    <button type="submit" class="btn small danger">{{ __('qr.deactivate') }}</button>
</form>

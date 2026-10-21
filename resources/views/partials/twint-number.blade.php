{{-- Copy buttons for the TWINT numbers (numbers are not shown); recipients without a number get none. --}}
@php($withPhone = array_values(array_filter($twintRecipients, fn ($r) => $r['phone'] !== '')))
@if ($withPhone !== [])
    <div class="row-buttons">
        @foreach ($withPhone as $r)
            <button type="button" class="btn small" data-copy-text="{{ $r['phone'] }}" data-copied="{{ __('account.copied') }}">{{ count($withPhone) > 1 && $r['name'] !== '' ? __('account.copy_number_of', ['name' => $r['name']]) : __('account.copy_number') }}</button>
        @endforeach
    </div>
@endif

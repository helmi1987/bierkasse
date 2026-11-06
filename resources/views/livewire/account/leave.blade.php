{{-- Leaving the beer account (Livewire since 1.35.0; see App\Livewire\Account\Leave). Polls every 10 s while
     visible; only the island "status" (balance, blocked alert) is re-rendered, the form stays as rendered at mount
     (the POST checks again whether leaving is possible). --}}
<div wire:poll.10s.visible="tick" data-testid="leave">
<h1>{{ __('account.leave_title') }}</h1>
<p>{{ __('account.leave_intro') }}</p>

@island('status', with: $__livewire->data())
<p class="lead" data-testid="leave-balance">{{ __('account.leave_balance', ['amount' => chf($balance)]) }}</p>
@if ($blocked)
    <div class="alert warn" data-testid="leave-blocked">{{ __('account.leave_blocked') }} {{ $blocked }}</div>
@endif
@endisland

@if (! $blocked)
    <form method="post" action="{{ route('account.leave.store') }}" class="card stack" data-confirm="{{ __('account.leave_confirm') }}">
        @csrf
        <label class="choice">
            <input type="radio" name="choice" value="payout" required @disabled($balance <= 0)>
            <span><strong>{{ __('account.leave_payout') }}</strong><br><span class="muted">{{ __('account.leave_payout_hint') }}</span></span>
        </label>
        <label class="choice">
            <input type="radio" name="choice" value="donate" required>
            <span><strong>{{ __('account.leave_donate') }}</strong><br><span class="muted">{{ __('account.leave_donate_hint') }}</span></span>
        </label>
        <label class="check"><input type="checkbox" name="confirm" value="1" required> {{ __('account.leave_confirm') }}</label>
        <button type="submit" class="btn danger big">{{ __('account.leave_submit') }}</button>
    </form>
@endif
</div>

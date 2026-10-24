{{-- Dismiss button of a "Runde/Bier erhalten" notice: stored for the user, the notice never shows again. --}}
<form method="post" action="{{ route('account.notice.dismiss', $transaction) }}" class="notice-dismiss">
    @csrf
    <button type="submit" class="notice-close" title="{{ __('account.notice_dismiss') }}" aria-label="{{ __('account.notice_dismiss') }}">×</button>
</form>

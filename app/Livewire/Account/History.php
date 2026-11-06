<?php

namespace App\Livewire\Account;

use App\Livewire\Concerns\WatchesAssets;
use App\Models\Transaction;
use App\Support\LiveFingerprint;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Own booking history "/konto/verlauf" (Livewire since 1.35.0): balance and all own bookings, 25 per page
 * (page in the URL, ?page=2 opens page 2 directly).
 *
 * Polls every 10 s while visible (tick()); nothing is rendered unless the global change marker
 * (App\Support\LiveFingerprint) differs from the last render or FORCE_SECONDS passed. Then the whole component
 * is re-rendered and morphed in place (no user input on this page).
 */
class History extends Component
{
    use WatchesAssets;
    use WithPagination;

    public const PER_PAGE = 25;

    /** The component is re-rendered at least this often. */
    public const FORCE_SECONDS = 300;

    /** Change marker of the last render. */
    public string $fp = '';

    /** Unix time of the last render. */
    public int $renderedAt = 0;

    /** Every request (first render and each poll): only accounts with a beer account; the page route has "beer" too. */
    public function boot(): void
    {
        abort_unless((bool) auth()->user()?->hasBeerAccount(), 403);
    }

    public function mount(): void
    {
        $this->fp = LiveFingerprint::current();
        $this->renderedAt = time();
        $this->rememberAssets();
    }

    /** wire:poll: nothing is rendered unless something changed (or FORCE_SECONDS passed); then the whole component. */
    public function tick(): void
    {
        $this->checkAssets();

        $fp = LiveFingerprint::current();
        if ($fp === $this->fp && time() - $this->renderedAt < self::FORCE_SECONDS) {
            $this->skipRender();

            return;
        }
        $this->fp = $fp;
        $this->renderedAt = time();
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.account.history', [
            'balance' => $user->availableBalance(),
            'transactions' => Transaction::query()->with(['roundItems.user', 'giftParts.user', 'giftParent.user'])
                ->where('user_id', $user->id)->latest('id')->paginate(self::PER_PAGE),
        ]);
    }
}

<?php

namespace App\Livewire\Account;

use App\Livewire\Concerns\WatchesAssets;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Support\LiveFingerprint;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Leaving the beer account "/konto/verlassen" (Livewire since 1.35.0): balance, a blocking reason if any, and the
 * form (payout or donate). The form posts classically to AccountController::leave(), which checks again.
 *
 * Polls every 10 s while visible (tick()). Only the island "status" (balance line and blocked alert) is
 * re-rendered, and only when the global change marker (App\Support\LiveFingerprint) differs from the last
 * render or FORCE_SECONDS passed; the form is rendered once at mount and never touched by a poll.
 */
class Leave extends Component
{
    use WatchesAssets;

    /** Islands of the view, re-rendered together by tick(). */
    public const ISLANDS = ['status'];

    /** Islands are re-rendered at least this often. */
    public const FORCE_SECONDS = 300;

    /** Change marker of the last island render. */
    public string $fp = '';

    /** Unix time of the last island render. */
    public int $renderedAt = 0;

    private ?array $cache = null;

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

    /** wire:poll: nothing is rendered unless something changed (or FORCE_SECONDS passed); then the island only. */
    public function tick(): void
    {
        $this->skipRender();
        $this->checkAssets();

        $fp = LiveFingerprint::current();
        if ($fp === $this->fp && time() - $this->renderedAt < self::FORCE_SECONDS) {
            return;
        }
        $this->fp = $fp;
        $this->renderedAt = time();
        foreach (self::ISLANDS as $island) {
            $this->renderIsland($island);
        }
    }

    public function render(): View
    {
        return view('livewire.account.leave', $this->data());
    }

    /** View data of the whole page (full render) and of the island, computed once per request. */
    public function data(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $user = auth()->user();
        $blocked = null;
        try {
            Ledger::assertCanLeave($user);
        } catch (LedgerException $e) {
            $blocked = $e->getMessage();
        }

        return $this->cache = [
            'balance' => $user->availableBalance(),
            'blocked' => $blocked,
            'pendingPayout' => Ledger::hasPendingPayout($user),
        ];
    }
}

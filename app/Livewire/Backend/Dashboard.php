<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\CashBook;
use App\Services\Ledger;
use App\Support\LiveFingerprint;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Kassier overview "/admin" (Livewire since 1.36.0): stock and cash KPIs, pending deposits, payouts and
 * carry-overs, users in minus. The confirm/reject forms post classically to Backend\DashboardController.
 *
 * Polls every 10 s while visible (tick()). Only the islands (kpis, deposits, payouts, carryovers, minus) are
 * re-rendered, and only when the global change marker (App\Support\LiveFingerprint) differs from the last
 * render or FORCE_SECONDS passed. Rows carry wire:key; the per-row forms are wire:ignore, so a chosen payout
 * method or an edited carry-over amount survives a poll (a row that disappears is removed with its form).
 */
class Dashboard extends Component
{
    use AuthorizesAdminArea;

    /** Islands of the view, re-rendered together by tick(). */
    public const ISLANDS = ['kpis', 'deposits', 'payouts', 'carryovers', 'minus'];

    /** Islands are re-rendered at least this often. */
    public const FORCE_SECONDS = 300;

    /** Change marker of the last island render. */
    public string $fp = '';

    /** Unix time of the last island render. */
    public int $renderedAt = 0;

    private ?array $cache = null;

    public function mount(): void
    {
        $this->fp = LiveFingerprint::current();
        $this->renderedAt = time();
    }

    /** wire:poll: nothing is rendered unless something changed (or FORCE_SECONDS passed); then the islands only. */
    public function tick(): void
    {
        $this->skipRender();

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
        return view('livewire.backend.dashboard', $this->data());
    }

    /** View data of the page (full render) and of the islands, computed once per request. */
    public function data(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $balances = Ledger::balances();
        $minusIds = array_keys(array_filter($balances, fn ($b) => $b < 0));
        $minus = User::query()->whereIn('id', $minusIds)->hasBeerAccount()->get()
            ->map(fn (User $u) => ['user' => $u, 'balance' => $balances[$u->id]])
            ->sortBy('balance')->values();
        $stock = BeerStock::current();
        $stockMin = BeerStock::minimum();

        return $this->cache = [
            'deposits' => Ledger::pendingDeposits(),
            'payouts' => Ledger::pendingPayouts(),
            'carryovers' => Ledger::pendingCarryovers(),
            'minus' => $minus,
            'overview' => CashBook::overview(),
            'stock' => $stock,
            'stockMin' => $stockMin,
            'stockLow' => $stockMin > 0 && $stock < $stockMin,
        ];
    }
}

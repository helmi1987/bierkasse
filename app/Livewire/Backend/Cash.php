<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Models\CashEntry;
use App\Models\GuestSale;
use App\Models\Transaction;
use App\Services\BeerStock;
use App\Services\CashBook;
use App\Support\LiveFingerprint;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cash book "/admin/kasse" (Livewire since 1.36.0; the tab "Einzahlungsvorschläge" is its own component):
 * KPIs, the form for a new entry and the entries (30 per page, page in the URL). The forms post classically
 * to Backend\CashController.
 *
 * Polls every 10 s while visible (tick()). Only the islands (kpis, entries) are re-rendered, and only when the
 * global change marker (App\Support\LiveFingerprint) differs from the last render or FORCE_SECONDS passed; the
 * new-entry form is rendered once at mount. The page buttons sit inside the island "entries" (a page change
 * re-renders that island; it is "always" so a full render shows the current page too).
 */
class Cash extends Component
{
    use AuthorizesAdminArea;
    use WithPagination;

    public const PER_PAGE = 30;

    /** Islands of the view, re-rendered together by tick(). */
    public const ISLANDS = ['kpis', 'entries'];

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
        return view('livewire.backend.cash', $this->data());
    }

    /** View data of the page (full render) and of the islands, computed once per request. */
    public function data(): array
    {
        return $this->cache ??= [
            'overview' => CashBook::overview(),
            'entries' => CashEntry::query()->with('creator')->orderByDesc('date')->orderByDesc('id')->paginate(self::PER_PAGE),
            'deposits' => (int) Transaction::query()->confirmed()->where('type', Transaction::TYPE_DEPOSIT)->sum('amount'),
            'payouts' => (int) -Transaction::query()->confirmed()->where('type', Transaction::TYPE_PAYOUT)->sum('amount'),
            'guests' => (int) GuestSale::query()->sum('amount'),
            'stock' => BeerStock::current(),
            'stockLow' => BeerStock::isLow(),
        ];
    }
}

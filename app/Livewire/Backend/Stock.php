<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Models\StockCorrection;
use App\Services\BeerStock;
use App\Support\LiveFingerprint;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Beer stock "/admin/lager" (Livewire since 1.36.0): stock, minimum, the forms (correction, minimum) and the
 * corrections (20 per page, page in the URL). The forms post classically to Backend\StockController.
 *
 * Polls every 10 s while visible (tick()). Only the islands (status, corrections) are re-rendered, and only
 * when the global change marker (App\Support\LiveFingerprint) differs from the last render or FORCE_SECONDS
 * passed; the forms are rendered once at mount (the counted default is the stock at mount). The page buttons
 * sit inside the island "corrections" ("always": a full render shows the current page too).
 */
class Stock extends Component
{
    use AuthorizesAdminArea;
    use WithPagination;

    public const PER_PAGE = 20;

    /** Islands of the view, re-rendered together by tick(). */
    public const ISLANDS = ['status', 'corrections'];

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
        return view('livewire.backend.stock', $this->data());
    }

    /** View data of the page (full render) and of the islands, computed once per request. */
    public function data(): array
    {
        return $this->cache ??= [
            'stock' => BeerStock::current(),
            'minimum' => BeerStock::minimum(),
            'low' => BeerStock::isLow(),
            'corrections' => StockCorrection::query()->with('creator')->latest('id')->paginate(self::PER_PAGE),
        ];
    }
}

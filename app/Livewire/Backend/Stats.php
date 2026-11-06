<?php

namespace App\Livewire\Backend;

use App\Http\Controllers\Backend\StatsController;
use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Support\LiveFingerprint;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Backend statistics "/admin/statistik" (Livewire since 1.36.0). Period and filters come from the query
 * (?zeitraum=…&von=…&bis=…&benutzer=…&typ=…&gruppe=…&raster=…) at mount and are kept as properties, so a poll
 * shows the same report; the period bar and the filter form stay plain GET (export unchanged).
 *
 * Polls every 10 s while visible (tick()); nothing is rendered unless the global change marker
 * (App\Support\LiveFingerprint) differs from the last render or FORCE_SECONDS passed. Then the whole component
 * is re-rendered and morphed in place; the filter form and the custom-period form are wire:ignore.
 */
class Stats extends Component
{
    use AuthorizesAdminArea;

    /** The component is re-rendered at least this often (time-based content). */
    public const FORCE_SECONDS = 300;

    /** GET parameters of the page, kept for the polls (validated by StatsPeriod and BackendStats::filters()). */
    public ?string $zeitraum = null;

    public ?string $von = null;

    public ?string $bis = null;

    public ?string $benutzer = null;

    public ?string $typ = null;

    public ?string $gruppe = null;

    public ?string $raster = null;

    /** Change marker of the last render. */
    public string $fp = '';

    /** Unix time of the last render. */
    public int $renderedAt = 0;

    private ?array $cache = null;

    /** Query values of the page (strings or null, see backend/stats.blade.php). */
    public function mount(?string $zeitraum = null, ?string $von = null, ?string $bis = null, ?string $benutzer = null,
        ?string $typ = null, ?string $gruppe = null, ?string $raster = null): void
    {
        $this->zeitraum = $zeitraum;
        $this->von = $von;
        $this->bis = $bis;
        $this->benutzer = $benutzer;
        $this->typ = $typ;
        $this->gruppe = $gruppe;
        $this->raster = $raster;
        $this->fp = LiveFingerprint::current();
        $this->renderedAt = time();
    }

    /** wire:poll: nothing is rendered unless something changed (or FORCE_SECONDS passed); then the whole component. */
    public function tick(): void
    {
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
        return view('livewire.backend.stats', $this->data());
    }

    /** View data (Backend\StatsController::data() for the stored query), computed once per request. */
    public function data(): array
    {
        return $this->cache ??= StatsController::data(array_filter([
            'zeitraum' => $this->zeitraum,
            'von' => $this->von,
            'bis' => $this->bis,
            'benutzer' => $this->benutzer,
            'typ' => $this->typ,
            'gruppe' => $this->gruppe,
            'raster' => $this->raster,
        ], fn (?string $value) => $value !== null));
    }
}

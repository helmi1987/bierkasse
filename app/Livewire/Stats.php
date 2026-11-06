<?php

namespace App\Livewire;

use App\Http\Controllers\StatsController;
use App\Livewire\Concerns\WatchesAssets;
use App\Support\AppSettings;
use App\Support\LiveFingerprint;
use App\Support\StatsPeriod;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Statistics "/statistik" (Livewire since 1.35.0). The period comes from the query (?zeitraum=…&von=…&bis=…) at
 * mount and is kept as properties, so a poll shows the same period; the period bar keeps plain GET links.
 *
 * Polls every 10 s while visible (tick()); nothing is rendered unless the global change marker
 * (App\Support\LiveFingerprint) differs from the last render or FORCE_SECONDS passed. Then the whole component
 * is re-rendered and morphed in place (no user input except the custom-period form, which is wire:ignore).
 */
class Stats extends Component
{
    use WatchesAssets;

    /** The component is re-rendered at least this often (time-based content). */
    public const FORCE_SECONDS = 300;

    /** Period of the page (?zeitraum=…&von=…&bis=…), kept for the polls. */
    public ?string $zeitraum = null;

    public ?string $von = null;

    public ?string $bis = null;

    /** Change marker of the last render. */
    public string $fp = '';

    /** Unix time of the last render. */
    public int $renderedAt = 0;

    private ?array $cache = null;

    /** Every request (first render and each poll): off for everyone while the statistics are switched off. */
    public function boot(): void
    {
        abort_unless(AppSettings::statsEnabled(), 404);
    }

    /** Query values of the page (strings or null, see stats/index.blade.php); StatsPeriod validates them. */
    public function mount(?string $zeitraum = null, ?string $von = null, ?string $bis = null): void
    {
        $this->zeitraum = $zeitraum;
        $this->von = $von;
        $this->bis = $bis;
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
        return view('livewire.stats', $this->data());
    }

    /** View data (StatsController::data() for the stored period), computed once per request. */
    public function data(): array
    {
        return $this->cache ??= StatsController::data(StatsPeriod::fromQuery(array_filter([
            'zeitraum' => $this->zeitraum,
            'von' => $this->von,
            'bis' => $this->bis,
        ])));
    }
}

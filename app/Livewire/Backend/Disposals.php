<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Models\Disposal;
use App\Models\User;
use App\Services\Disposals as DisposalService;
use App\Support\AppSettings;
use App\Support\LiveFingerprint;
use Illuminate\Support\MessageBag;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Entsorgungsbonus" "/admin/entsorgung" (Livewire since 1.36.0): guide, settings, status, report for a user,
 * the list (50 per page, page in the URL) and the ranking. The forms post classically to
 * Backend\DisposalController.
 *
 * Polls every 10 s while visible (tick()). Only the islands (status, list, ranking) are re-rendered, and only
 * when the global change marker (App\Support\LiveFingerprint) differs from the last render or FORCE_SECONDS
 * passed (lock end, booking time). The guide (open while switched off), the settings form and the "for user"
 * form (user list) are rendered once at mount. Rows carry wire:key, the withdraw forms are wire:ignore.
 */
class Disposals extends Component
{
    use AuthorizesAdminArea;
    use WithPagination;

    public const PER_PAGE = 50;

    /** Islands of the view, re-rendered together by tick(). */
    public const ISLANDS = ['status', 'list', 'ranking'];

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
        return view('livewire.backend.disposals', $this->data());
    }

    /** View data of the page (full render) and of the islands, computed once per request. */
    public function data(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $viewer = auth()->user();
        $enabled = DisposalService::isEnabled();
        $last = $enabled ? DisposalService::lastActive()?->load('user') : null;

        return $this->cache = [
            'enabled' => $enabled,
            'enabledAt' => DisposalService::enabledAt(),
            'mode' => AppSettings::disposalMode(),
            'fixed' => AppSettings::disposalFixed(),
            'randomMax' => AppSettings::disposalRandomMax(),
            'lockDays' => AppSettings::disposalLockDays(),
            'minBeers' => AppSettings::disposalMinBeers(),
            'withdrawMinutes' => AppSettings::undoMinutes('disposal'),
            'rankingPublic' => AppSettings::disposalRankingPublic(),
            'last' => $last,
            'lockedUntil' => $last !== null ? DisposalService::lockedUntil($last) : null,
            'beersSince' => $last !== null ? DisposalService::beersSince($last->reported_at) : null,
            'blockReason' => DisposalService::blockReason(),
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->hasBeerAccount()
                ->whereKeyNot($viewer->id)->get()
                ->sortBy(fn (User $u) => mb_strtolower($u->displayName()))->values(),
            'disposals' => Disposal::query()->with(['user', 'reporter', 'withdrawer', 'transaction'])
                ->latest('reported_at')->latest('id')->paginate(self::PER_PAGE),
            'viewer' => $viewer,
            // Always shown here (regardless of "Rangliste öffentlich"), same anonymisation as on /statistik.
            'ranking' => DisposalService::ranking(20),
            'totals' => DisposalService::totals(),
            // Validation errors of the classic form posts (redirect back): inside a Livewire view $errors is the
            // component's own bag, so the session bag is passed explicitly for the forms.
            'formErrors' => session('errors')?->getBag('default') ?? new MessageBag,
        ];
    }
}

<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Disposals;
use App\Services\Gifts;
use App\Services\Ledger;
use App\Services\Rounds;
use App\Support\LiveFingerprint;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * User detail "/admin/benutzer/{user}" (Livewire since 1.36.0): heading with role, status and lock, balance,
 * the kassier forms (note, deposit, correction, limit, password), consent, actions and the history (25 per
 * page, page in the URL, storno per row). All forms post classically to Backend\UserController.
 *
 * Polls every 10 s while visible (tick()). Only the islands are re-rendered, and only when the global change
 * marker (App\Support\LiveFingerprint) differs from the last render or FORCE_SECONDS passed:
 * "balance" (heading with the status/lock pills, lock info, KPIs and the balance card; all of it can change
 * by others), "actions" (its buttons depend on status and lock; the lock, role and admin-only forms inside are
 * wire:ignore) and "history" (rows wire:key, storno <details> wire:ignore.self, storno form wire:ignore).
 * The other forms and the consent card are rendered once at mount.
 */
class UserShow extends Component
{
    use AuthorizesAdminArea;
    use WithPagination;

    public const PER_PAGE = 25;

    /** Islands of the view, re-rendered together by tick(). */
    public const ISLANDS = ['balance', 'actions', 'history'];

    /** Islands are re-rendered at least this often. */
    public const FORCE_SECONDS = 300;

    public User $user;

    /** Change marker of the last island render. */
    public string $fp = '';

    /** Unix time of the last island render. */
    public int $renderedAt = 0;

    private ?array $cache = null;

    public function mount(User $user): void
    {
        $this->user = $user;
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
        return view('livewire.backend.user-show', $this->data());
    }

    /** View data of the page (full render) and of the islands, computed once per request. */
    public function data(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $user = $this->user->loadMissing('lockedBy');
        $me = auth()->user();

        return $this->cache = [
            'user' => $user,
            'me' => $me,
            // Kassiers do not touch admin accounts (password, TOTP, status, lock).
            'protected' => $user->isAdmin() && ! $me->isAdmin(),
            'adminOnlySettled' => Ledger::adminOnlySettled($user),
            'balance' => $user->balance(),
            'personalSso' => $user->personalSsoProvider()->withCount('identities')->first(),
            'open' => Ledger::openItems($user),
            'transactions' => Transaction::query()->with(['creator', 'confirmer', 'roundItems.user', 'giftParts.user', 'giftParent.user'])->where('user_id', $user->id)->latest('id')->paginate(self::PER_PAGE),
            'roundsGiven' => Rounds::beersGiven($user),
            'giftsGiven' => Gifts::beersGiven($user),
            'giftsReceived' => Gifts::beersReceived($user),
            'disposals' => Disposals::countFor($user),
        ];
    }
}

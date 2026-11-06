<?php

namespace App\Livewire\Account;

use App\Livewire\Concerns\WatchesAssets;
use App\Models\GuestSale;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\Disposals;
use App\Services\Favorites;
use App\Services\Gifts;
use App\Services\Ledger;
use App\Services\Rounds;
use App\Services\Stats;
use App\Support\AppSettings;
use App\Support\BarChart;
use App\Support\DepositSuggestions;
use App\Support\LiveFingerprint;
use Illuminate\Support\MessageBag;
use Illuminate\View\View;
use Livewire\Component;

/**
 * User dashboard "/konto" (Livewire since 1.34.0): balance, beers, rounds, gifts, guests, disposal, deposits,
 * latest bookings and own statistics.
 *
 * Live without jumping: the component polls every 10 s while visible (wire:poll on the root, action tick()).
 * Only the parts others can change are islands (@island in the view: balance, notices, stock, undo lists,
 * disposal card, TWINT amount, pending deposits, bookings) and are morphed in place; the forms, the cards'
 * <details> and everything the user types in are rendered once at mount and never touched by a poll.
 * A poll re-renders the islands only when the global change marker (App\Support\LiveFingerprint) differs
 * from the one of the last render, or after FORCE_SECONDS for time-based content (undo windows, day stats).
 * Form tokens (App\Support\FormToken) are therefore issued once per page view like before.
 */
class Dashboard extends Component
{
    use WatchesAssets;

    /** Islands of the view, re-rendered together by tick(). */
    public const ISLANDS = ['balance', 'notices', 'stock', 'undo-consume', 'undo-round', 'undo-gift', 'undo-guest', 'disposal', 'deposit-twint', 'pending', 'bookings'];

    /** Islands are re-rendered at least this often (time-based content). */
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

    /** wire:poll: nothing is rendered unless something changed (or FORCE_SECONDS passed); then the islands only. */
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
        return view('livewire.account.dashboard', $this->data());
    }

    /**
     * View data of the whole dashboard (full render) and of the islands (@island(..., with: $__livewire->data())),
     * computed once per request.
     */
    public function data(): array
    {
        return $this->cache ??= $this->compute(auth()->user());
    }

    private function compute(User $user): array
    {
        // Includes reported deposits/carry-overs not yet confirmed (decision 2026-10-23): one number for the user.
        // Pending deposits are left out while on hold (decision 2026-10-24, Ledger::depositsOnHold()).
        $balance = $user->availableBalance();
        $price = AppSettings::beerPrice();
        // Own statistics only when switched on (Admin > Einstellungen > Funktionen): cheap page otherwise.
        $stats = AppSettings::statsDashboardEnabled() ? Stats::forUser($user) : null;

        $undoable = Transaction::query()->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_CONSUMPTION)
            ->where('created_by', $user->id)
            ->where('created_at', '>', now()->subMinutes(AppSettings::undoMinutes('consume')))
            ->latest('id')->get();

        $suggestions = DepositSuggestions::for($balance, $price, AppSettings::depositSuggestions());
        $suggested = $suggestions[DepositSuggestions::defaultIndex($suggestions, AppSettings::depositDefault(), $price)] ?? null;

        // Booking for guests (own guest sales within the undo window can be undone).
        $guestBooking = AppSettings::guestUserBooking();
        $guestUndoable = GuestSale::query()->where('booked_by', $user->id)
            ->where('created_at', '>', now()->subMinutes(AppSettings::undoMinutes('guest')))
            ->latest('id')->get();

        // "Runde spendieren": colleagues to choose from, own rounds within the undo window, rounds received.
        $rounds = AppSettings::roundsAllowed();
        $roundUndoable = $rounds ? Transaction::query()->with('roundItems.user')->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_ROUND)->where('created_by', $user->id)
            ->where('created_at', '>', now()->subMinutes(AppSettings::undoMinutes('round')))
            ->latest('id')->get() : collect();

        // "Bier verschenken": own gifts within the undo window, gifts received.
        $gifts = AppSettings::giftsAllowed();
        $giftUndoable = $gifts ? Transaction::query()->with('giftParts.user')->where('user_id', $user->id)
            ->where('type', Transaction::TYPE_GIFT)->whereNull('parent_id')
            ->where('created_at', '>', now()->subMinutes(AppSettings::undoMinutes('gift')))
            ->latest('id')->get()->reject(fn (Transaction $tx) => Gifts::recipientsBookedSince($tx))->values() : collect();

        // "Entsorgungsbonus": card only when a report is possible or the user has an own pending one (nothing else is shown).
        $disposalPending = Disposals::isEnabled() ? Disposals::ownPending($user) : null;
        $disposalCanReport = $disposalPending === null && Disposals::canReport($user);

        // Beers left in the cellar (only once the kassier maintains the stock).
        $stock = BeerStock::isTracked() ? BeerStock::current() : null;

        // Same colleagues in both lists, but each list orders them by its own favourites (separate instances: arrange() sets attributes).
        $colleagues = ['round' => collect(), 'gift' => collect()];
        if ($rounds || $gifts) {
            $base = Rounds::colleagues($user);
            foreach (Favorites::LISTS as $list) {
                $colleagues[$list] = Favorites::arrange($base->map(fn (User $c) => clone $c), Favorites::ids($user, $list));
            }
        }

        return [
            'user' => $user,
            'stock' => $stock,
            'stockLow' => $stock !== null && BeerStock::isLow($stock),
            'guestBooking' => $guestBooking,
            'roundBarTop' => AppSettings::roundsBarTop(),
            'giftBarTop' => AppSettings::giftsBarTop(),
            'guestBarTop' => AppSettings::guestBarTop(),
            'guestUndoable' => $guestUndoable,
            'rounds' => $rounds,
            'colleagues' => $colleagues['round'],
            'giftColleagues' => $colleagues['gift'],
            'gifts' => $gifts,
            'giftUndoable' => $giftUndoable,
            'giftsReceived' => Gifts::receivedRecently($user),
            'disposalPending' => $disposalPending,
            'disposalCanReport' => $disposalCanReport,
            'disposalsBooked' => Disposals::bookedRecently($user),
            'roundUndoable' => $roundUndoable,
            'roundsReceived' => $rounds ? Rounds::receivedRecently($user) : collect(),
            'balance' => $balance,
            'price' => $price,
            'open' => Ledger::openItems($user, true),
            'undoable' => $undoable,
            'pending' => Transaction::query()->where('user_id', $user->id)->pending()->latest('id')->get(),
            'depositsOnHold' => Ledger::depositsOnHold($user),
            'twintEnabled' => AppSettings::twintEnabled(),
            'twintRecipients' => AppSettings::twintRecipients(),
            'twintHtml' => AppSettings::twintRecipientText(true),
            'suggestions' => $suggestions,
            'suggested' => $suggested,
            'recent' => Transaction::query()->with(['roundItems.user', 'giftParts.user', 'giftParent.user'])->where('user_id', $user->id)->latest('id')->limit(5)->get(),
            'stats' => $stats,
            'statsAmounts' => AppSettings::statsShows('amounts'),
            // Fixed comparison (this/previous month and year) of the own beers (1.30.0).
            'comparison' => $stats !== null ? Stats::comparison($user) : null,
            'statsLink' => AppSettings::statsEnabled(),
            'canInvite' => AppSettings::invitationsAllowed(),
            'chart' => $stats !== null ? BarChart::render(
                array_column($stats['months'], 'label'),
                array_column($stats['months'], 'beers'),
                __('stats.chart_my_months'),
            ) : null,
            // Validation errors of the classic form posts (redirect back): inside a Livewire view $errors is the
            // component's own bag, so the session bag is passed explicitly for the forms (open card, old input).
            'formErrors' => session('errors')?->getBag('default') ?? new MessageBag,
        ];
    }
}

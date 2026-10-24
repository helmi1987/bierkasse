<?php

namespace App\Livewire\Backend;

use App\Livewire\Concerns\AuthorizesAdminArea;
use App\Livewire\Concerns\HasTabs;
use App\Models\GuestSale;
use App\Models\Transaction;
use App\Services\GuestSales;
use App\Support\AppSettings;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Buchungen" (kassier and admin): tabs "Runden", "Geschenke" and "Gäste" with the lists of rounds, gifts and
 * guest sales (guest sales can be cancelled). The feature switches are in Admin > Einstellungen > Funktionen.
 */
class Bookings extends Component
{
    use AuthorizesAdminArea;
    use HasTabs;
    use WithPagination;

    public const TABS = ['runden', 'geschenke', 'gaeste'];

    public const PER_PAGE = 30;

    #[Url(as: 'tab', except: 'runden')]
    public string $tab = 'runden';

    /** Result of the last action: ['type' => 'ok'|'bad', 'text' => …]. */
    public array $notice = [];

    /** Storno of a guest sale (same service as before: cash income, stock and statistics follow). */
    public function cancelGuestSale(int $sale): void
    {
        $guestSale = GuestSale::query()->find($sale);
        if ($guestSale === null) {
            $this->notice = ['type' => 'bad', 'text' => __('guest.cancel_missing')];

            return;
        }
        GuestSales::cancel($guestSale);
        $this->notice = ['type' => 'ok', 'text' => __('guest.cancelled')];
    }

    public function render(): View
    {
        return view('livewire.backend.bookings', match ($this->tab) {
            'geschenke' => $this->gifts(),
            'gaeste' => $this->guests(),
            default => $this->rounds(),
        });
    }

    private function rounds(): array
    {
        $rounds = Transaction::query()->confirmed()->where('type', Transaction::TYPE_ROUND);

        return [
            'allowed' => AppSettings::roundsAllowed(),
            'rows' => (clone $rounds)->with(['user', 'roundItems.user'])->latest('id')->paginate(self::PER_PAGE),
            'total' => ['beers' => (int) (clone $rounds)->sum('quantity'), 'amount' => (int) -(clone $rounds)->sum('amount')],
        ];
    }

    private function gifts(): array
    {
        $gifts = Transaction::query()->confirmed()->where('type', Transaction::TYPE_GIFT)->whereNull('parent_id');

        return [
            'allowed' => AppSettings::giftsAllowed(),
            'rows' => (clone $gifts)->with(['user', 'giftParts.user'])->latest('id')->paginate(self::PER_PAGE),
            'total' => ['beers' => (int) (clone $gifts)->sum('quantity'), 'amount' => (int) -(clone $gifts)->sum('amount')],
        ];
    }

    private function guests(): array
    {
        return [
            'allowed' => AppSettings::guestUserBooking() || AppSettings::guestOpen() || (bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED, false),
            'userBooking' => AppSettings::guestUserBooking(),
            'open' => AppSettings::guestOpen(),
            'cashboxEnabled' => (bool) AppSettings::get(AppSettings::CASHBOX_QR_ENABLED, false),
            'rows' => GuestSale::query()->with('bookedBy')->latest('id')->paginate(self::PER_PAGE),
            'total' => ['beers' => (int) GuestSale::query()->sum('beers'), 'amount' => (int) GuestSale::query()->sum('amount')],
        ];
    }
}

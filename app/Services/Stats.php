<?php

namespace App\Services;

use App\Models\GuestSale;
use App\Models\RoundItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consumption statistics. Users with stats_opt_out are never named for other users:
 * their beers count only in the anonymous totals.
 *
 * Beers count at the drinker: own consumption, beers received in a round (at the round's time) and
 * guest sales ("Gäste"). Beers without a name in a round count as "Gäste (eingeladen)". The round
 * transaction itself is never counted as beers (only its items), so nothing is counted twice.
 */
class Stats
{
    public const MONTHS = 12;

    /** Pseudo user id of the guest sales in the ranking. */
    public const GUESTS_ID = 0;

    /** Pseudo user id of the beers without a name from rounds in the ranking. */
    public const INVITED_ID = -1;

    /**
     * @return array{total_beers:int, total_amount:int, per_week:float, months: list<array{label:string, beers:int, amount:int}>, given:int}
     */
    public static function forUser(User $user): array
    {
        $own = self::consumptions()->where('user_id', $user->id)->get(['quantity', 'amount', 'created_at']);
        // Beers received in rounds: drunk by the user, paid by the giver (amount 0 here).
        $received = RoundItem::query()->where('user_id', $user->id)
            ->whereHas('round', fn ($q) => $q->confirmed())->with('round:id,created_at')->get()
            ->map(fn (RoundItem $i) => (object) ['quantity' => $i->quantity, 'amount' => 0, 'created_at' => $i->round->created_at]);
        $rows = $own->toBase()->concat($received);
        $first = $rows->min('created_at');
        $beers = (int) $rows->sum('quantity');
        $weeks = $first ? max(1, Carbon::parse($first)->diffInDays(now()) / 7) : 1;
        $rounds = Transaction::query()->confirmed()->where('user_id', $user->id)->where('type', Transaction::TYPE_ROUND)
            ->selectRaw('COALESCE(SUM(quantity), 0) as beers, COALESCE(SUM(amount), 0) as amount')->first();

        return [
            'total_beers' => $beers,
            // Spent on beers: own consumption and the rounds paid for others.
            'total_amount' => (int) -$own->sum('amount') - (int) $rounds->amount,
            'per_week' => round($beers / $weeks, 1),
            'months' => self::months($rows),
            'given' => (int) $rounds->beers,
        ];
    }

    /**
     * @return array{total_beers:int, months: list<array{label:string, beers:int, amount:int}>, weekdays: list<array{label:string, beers:int}>, ranking: list<array{user_id:int, name:string, beers:int}>}
     */
    public static function global(int $top = 10): array
    {
        $rows = self::consumptions()->get(['user_id', 'quantity', 'amount', 'created_at']);
        // Guest sales as pseudo rows (user_id 0, shown as "Gäste"), never as a user.
        $guests = GuestSale::query()->get(['beers', 'amount', 'created_at'])
            ->map(fn (GuestSale $g) => (object) ['user_id' => self::GUESTS_ID, 'quantity' => $g->beers, 'amount' => -$g->amount, 'created_at' => $g->created_at]);
        $rows = $rows->toBase()->concat($guests)->concat(self::roundRows());

        return [
            'total_beers' => (int) $rows->sum('quantity'),
            'months' => self::months($rows),
            'weekdays' => self::weekdays($rows),
            'ranking' => self::ranking($rows, $top),
        ];
    }

    private static function consumptions()
    {
        return Transaction::query()->confirmed()->where('type', Transaction::TYPE_CONSUMPTION);
    }

    /**
     * Beers of all confirmed rounds as rows of the drinker (user_id INVITED_ID for beers without
     * a name), at the time and price of the round.
     *
     * @return Collection<int, object{user_id:int, quantity:int, amount:int, created_at:Carbon}>
     */
    private static function roundRows(): Collection
    {
        return Transaction::query()->confirmed()->where('type', Transaction::TYPE_ROUND)
            ->with('roundItems:id,transaction_id,user_id,quantity')
            ->get(['id', 'unit_price', 'created_at'])
            ->flatMap(fn (Transaction $tx) => $tx->roundItems->map(fn ($item) => (object) [
                'user_id' => $item->user_id ?? self::INVITED_ID,
                'quantity' => $item->quantity,
                'amount' => -$item->quantity * (int) $tx->unit_price,
                'created_at' => $tx->created_at,
            ]))->toBase();
    }

    /**
     * @param  Collection<int, Transaction>  $rows
     * @return list<array{label:string, beers:int, amount:int}>
     */
    private static function months(Collection $rows): array
    {
        $tz = (string) config('app.timezone');
        $months = [];
        $start = now($tz)->startOfMonth()->subMonths(self::MONTHS - 1);
        for ($i = 0; $i < self::MONTHS; $i++) {
            $m = $start->copy()->addMonths($i);
            $months[$m->format('Y-m')] = ['label' => __('stats.month_short.'.$m->month).' '.$m->format('y'), 'beers' => 0, 'amount' => 0];
        }

        foreach ($rows as $row) {
            $key = $row->created_at->copy()->setTimezone($tz)->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['beers'] += (int) $row->quantity;
                $months[$key]['amount'] += (int) -$row->amount;
            }
        }

        return array_values($months);
    }

    /**
     * @param  Collection<int, Transaction>  $rows
     * @return list<array{label:string, beers:int}>
     */
    private static function weekdays(Collection $rows): array
    {
        $tz = (string) config('app.timezone');
        $days = [];
        for ($d = 1; $d <= 7; $d++) {
            $days[$d] = ['label' => __('stats.weekday_short.'.$d), 'beers' => 0];
        }
        foreach ($rows as $row) {
            $days[(int) $row->created_at->copy()->setTimezone($tz)->format('N')]['beers'] += (int) $row->quantity;
        }

        return array_values($days);
    }

    /**
     * @param  Collection<int, Transaction>  $rows
     * @return list<array{user_id:int, name:string, beers:int}>
     */
    private static function ranking(Collection $rows, int $top): array
    {
        $sums = $rows->groupBy('user_id')->map(fn ($g) => (int) $g->sum('quantity'));
        // "Nur Verwaltung" accounts are never ranked (older beers count in the totals only).
        $users = User::query()->whereIn('id', $sums->keys())->where('stats_opt_out', false)->hasBeerAccount()->get()->keyBy('id');
        $pseudo = [self::GUESTS_ID => __('stats.guests'), self::INVITED_ID => __('stats.guests_invited')];
        $name = fn ($id) => $pseudo[(int) $id] ?? $users[$id]->displayName();

        return $sums->filter(fn ($beers, $id) => (isset($pseudo[(int) $id]) || $users->has($id)) && $beers > 0)
            ->sortDesc()->take($top)
            ->map(fn ($beers, $id) => ['user_id' => (int) $id, 'name' => $name($id), 'beers' => $beers])
            ->values()->all();
    }
}

<?php

namespace App\Services;

use App\Models\GuestSale;
use App\Models\RoundItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consumption statistics. Users with stats_opt_out are never named for other users: in the ranking
 * they appear as "Anonym n", numbered by their position in the shown list (not fixed per user, 1.29.0).
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
     * @return array{total_beers:int, total_amount:int, per_week:float, months: list<array{label:string, beers:int, amount:int}>, given:int, gifted:int, gift_received:int}
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
            // Spent on beers: own consumption, the rounds paid for others and beers given away.
            'total_amount' => (int) -$own->sum('amount') - (int) $rounds->amount - (int) Transaction::query()->confirmed()
                ->where('user_id', $user->id)->where('type', Transaction::TYPE_GIFT)->whereNull('parent_id')->sum('amount'),
            'per_week' => round($beers / $weeks, 1),
            'months' => self::months($rows),
            // Paid for others: the own beer of "Runde inkl. mir" counts as own beer above.
            'given' => (int) $rounds->beers - Rounds::ownBeers($user),
            // "Bier verschenken" (credit, not drunk): never counted as beers above.
            'gifted' => Gifts::beersGiven($user),
            'gift_received' => Gifts::beersReceived($user),
        ];
    }

    /**
     * @return array{total_beers:int, months: list<array{label:string, beers:int, amount:int}>, weekdays: list<array{label:string, beers:int}>, ranking: list<array{rank:int, user_id:int, name:string, beers:int, anonymous:bool}>}
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
     * Ranking with standard competition ranking ("1224"): equal beers share the rank, the next rank skips
     * the tied places (1, 1, 3). All entries tied with place $top are shown too. Users with stats_opt_out
     * are listed as "Anonym 1", "Anonym 2", … from top to bottom of the shown list; user_id stays for the
     * own-row highlight (never rendered).
     *
     * @param  Collection<int, Transaction>  $rows
     * @return list<array{rank:int, user_id:int, name:string, beers:int, anonymous:bool}>
     */
    private static function ranking(Collection $rows, int $top): array
    {
        $sums = $rows->groupBy('user_id')->map(fn ($g) => (int) $g->sum('quantity'));
        // "Nur Verwaltung" accounts are never ranked (older beers count in the totals only).
        $users = User::query()->whereIn('id', $sums->keys())->hasBeerAccount()->get()->keyBy('id');
        $pseudo = [self::GUESTS_ID => __('stats.guests'), self::INVITED_ID => __('stats.guests_invited')];

        $sorted = $sums->filter(fn ($beers, $id) => (isset($pseudo[(int) $id]) || $users->has($id)) && $beers > 0)
            ->sortDesc();
        $ranked = self::ranked($sorted->map(fn ($beers, $id) => [
            'user_id' => (int) $id,
            'name' => $pseudo[(int) $id] ?? $users[$id]->displayName(),
            'beers' => $beers,
            'anonymous' => ! isset($pseudo[(int) $id]) && (bool) $users[$id]->stats_opt_out,
        ])->values(), 'beers', $top);

        // Numbered after sorting and cutting, so the shown list reads Anonym 1, 2, … top to bottom.
        $n = 0;
        foreach ($ranked as $i => $row) {
            if ($row['anonymous']) {
                $ranked[$i]['name'] = __('stats.anonymous', ['n' => ++$n]);
            }
        }

        return $ranked;
    }

    /**
     * Adds "rank" to rows sorted descending by $field (ties share the rank, 1224) and cuts after place $top,
     * keeping every row tied with it.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function ranked(Collection $rows, string $field, int $top): array
    {
        $out = [];
        $rank = 0;
        $previous = null;
        foreach ($rows->values() as $i => $row) {
            if ($previous === null || $row[$field] !== $previous) {
                $rank = $i + 1;
                $previous = $row[$field];
            }
            if ($rank > $top) {
                break;
            }
            $out[] = ['rank' => $rank] + $row;
        }

        return $out;
    }
}

<?php

namespace App\Services;

use App\Models\GuestSale;
use App\Models\RoundItem;
use App\Models\Transaction;
use App\Models\User;
use App\Support\StatsPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consumption statistics. Users with stats_opt_out are never named for other users: in the ranking
 * they appear as "Anonym n", numbered by their position in the shown list (not fixed per user, 1.29.0).
 *
 * Beers count at the drinker: own consumption, beers received in a round (at the round's time) and
 * guest sales ("Gäste"). Beers without a name in a round count as "Gäste (eingeladen)". The round
 * transaction itself is never counted as beers (only its items), so nothing is counted twice.
 * Gifts ("Bier verschenken") are credit, never drunk beers.
 *
 * Periods (1.30.0): global() takes a StatsPeriod; the rows are filtered in SQL by created_at (rounds by the
 * round transaction's created_at) and only the rows of the period are loaded. comparison() sums the fixed
 * ranges (month, previous month, year, previous year) with aggregate queries, independent of the period.
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
            'months' => self::course($rows, StatsPeriod::all()),
            // Paid for others: the own beer of "Runde inkl. mir" counts as own beer above.
            'given' => (int) $rounds->beers - Rounds::ownBeers($user),
            // "Bier verschenken" (credit, not drunk): never counted as beers above.
            'gifted' => Gifts::beersGiven($user),
            'gift_received' => Gifts::beersReceived($user),
        ];
    }

    /**
     * Statistics of all users for a period (default "gesamt": all time, course = last MONTHS months).
     * "course" holds the chart buckets of the period (days or months, see StatsPeriod::buckets()).
     *
     * @return array{total_beers:int, course: list<array{label:string, beers:int, amount:int}>, weekdays: list<array{label:string, beers:int}>, ranking: list<array{rank:int, user_id:int, name:string, beers:int, anonymous:bool}>}
     */
    public static function global(int $top = 10, ?StatsPeriod $period = null): array
    {
        $period ??= StatsPeriod::all();
        $bounds = $period->dbBounds();
        $rows = self::consumptions()->when($bounds, fn ($q) => $q->whereBetween('created_at', $bounds))
            ->get(['user_id', 'quantity', 'amount', 'created_at']);
        // Guest sales as pseudo rows (user_id 0, shown as "Gäste"), never as a user.
        $guests = GuestSale::query()->when($bounds, fn ($q) => $q->whereBetween('created_at', $bounds))
            ->get(['beers', 'amount', 'created_at'])
            ->map(fn (GuestSale $g) => (object) ['user_id' => self::GUESTS_ID, 'quantity' => $g->beers, 'amount' => -$g->amount, 'created_at' => $g->created_at]);
        $rows = $rows->toBase()->concat($guests)->concat(self::roundRows($bounds));

        return [
            'total_beers' => (int) $rows->sum('quantity'),
            'course' => self::course($rows, $period),
            'weekdays' => self::weekdays($rows),
            'ranking' => self::ranking($rows, $top),
        ];
    }

    /**
     * Beers this month, previous month, change in percent (null when the previous month is 0), this year and
     * previous year, in the app time zone and independent of any selected period. Without a user: everybody
     * (same sources as global()); with a user: that user's beers like forUser() (own consumption and beers
     * received in rounds). One aggregate query per source.
     *
     * @return array{month:int, previous_month:int, change:?float, year:int, previous_year:int}
     */
    public static function comparison(?User $user = null): array
    {
        $now = now((string) config('app.timezone'));
        $ranges = [
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'previous_month' => [$now->copy()->startOfMonth()->subMonthNoOverflow(), $now->copy()->startOfMonth()->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'previous_year' => [$now->copy()->startOfYear()->subYearNoOverflow(), $now->copy()->startOfYear()->subYearNoOverflow()->endOfYear()],
        ];
        $ranges = array_map(fn (array $r) => [StatsPeriod::toDb($r[0]), StatsPeriod::toDb($r[1])], $ranges);

        $sources = [
            [self::consumptions()->when($user, fn ($q) => $q->where('user_id', $user->id)), 'created_at', 'quantity'],
            // The round transaction itself never counts, only its items (at the round's time).
            [RoundItem::query()->join('transactions', 'transactions.id', '=', 'round_items.transaction_id')
                ->where('transactions.status', Transaction::STATUS_CONFIRMED)->where('transactions.type', Transaction::TYPE_ROUND)
                ->when($user, fn ($q) => $q->where('round_items.user_id', $user->id)), 'transactions.created_at', 'round_items.quantity'],
        ];
        if ($user === null) {
            $sources[] = [GuestSale::query(), 'created_at', 'beers'];
        }

        $sums = array_fill_keys(array_keys($ranges), 0);
        foreach ($sources as [$query, $date, $quantity]) {
            $row = self::sumRanges($query, $date, $quantity, $ranges);
            foreach ($sums as $key => $value) {
                $sums[$key] = $value + (int) $row->{$key};
            }
        }

        return [
            'month' => $sums['month'],
            'previous_month' => $sums['previous_month'],
            'change' => $sums['previous_month'] > 0 ? round(($sums['month'] - $sums['previous_month']) / $sums['previous_month'] * 100, 1) : null,
            'year' => $sums['year'],
            'previous_year' => $sums['previous_year'],
        ];
    }

    /**
     * One row with a column per range: SUM(quantity) of the rows whose date lies in the range.
     *
     * @param  array<string, array{0:string, 1:string}>  $ranges  Column alias => DB bounds.
     */
    private static function sumRanges(Builder $query, string $date, string $quantity, array $ranges): object
    {
        $select = [];
        $bindings = [];
        foreach ($ranges as $alias => [$from, $to]) {
            $select[] = "COALESCE(SUM(CASE WHEN {$date} BETWEEN ? AND ? THEN {$quantity} ELSE 0 END), 0) AS {$alias}";
            array_push($bindings, $from, $to);
        }
        $all = array_merge(...array_values($ranges));

        return $query->whereBetween($date, [min($all), max($all)])->selectRaw(implode(', ', $select), $bindings)->toBase()->first();
    }

    private static function consumptions()
    {
        return Transaction::query()->confirmed()->where('type', Transaction::TYPE_CONSUMPTION);
    }

    /**
     * Beers of all confirmed rounds as rows of the drinker (user_id INVITED_ID for beers without
     * a name), at the time and price of the round; optionally only rounds within DB bounds.
     *
     * @param  array{0:string, 1:string}|null  $bounds
     * @return Collection<int, object{user_id:int, quantity:int, amount:int, created_at:Carbon}>
     */
    private static function roundRows(?array $bounds = null): Collection
    {
        return Transaction::query()->confirmed()->where('type', Transaction::TYPE_ROUND)
            ->when($bounds, fn ($q) => $q->whereBetween('created_at', $bounds))
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
     * Beers and amount per chart bucket of the period (rows outside the buckets are ignored).
     *
     * @param  Collection<int, Transaction>  $rows
     * @return list<array{label:string, beers:int, amount:int}>
     */
    private static function course(Collection $rows, StatsPeriod $period): array
    {
        $buckets = array_map(fn (string $label) => ['label' => $label, 'beers' => 0, 'amount' => 0], $period->buckets());
        foreach ($rows as $row) {
            $key = $period->bucketKey($row->created_at);
            if (isset($buckets[$key])) {
                $buckets[$key]['beers'] += (int) $row->quantity;
                $buckets[$key]['amount'] += (int) -$row->amount;
            }
        }

        return array_values($buckets);
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

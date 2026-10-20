<?php

namespace App\Services;

use App\Models\GuestSale;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Consumption statistics. Users with stats_opt_out are never named for other users:
 * their beers count only in the anonymous totals.
 */
class Stats
{
    public const MONTHS = 12;

    /**
     * @return array{total_beers:int, total_amount:int, per_week:float, months: list<array{label:string, beers:int, amount:int}>}
     */
    public static function forUser(User $user): array
    {
        $rows = self::consumptions()->where('user_id', $user->id)->get(['quantity', 'amount', 'created_at']);
        $first = $rows->min('created_at');
        $beers = (int) $rows->sum('quantity');
        $weeks = $first ? max(1, Carbon::parse($first)->diffInDays(now()) / 7) : 1;

        return [
            'total_beers' => $beers,
            'total_amount' => (int) -$rows->sum('amount'),
            'per_week' => round($beers / $weeks, 1),
            'months' => self::months($rows),
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
            ->map(fn (GuestSale $g) => (object) ['user_id' => 0, 'quantity' => $g->beers, 'amount' => -$g->amount, 'created_at' => $g->created_at]);
        $rows = $rows->toBase()->concat($guests);

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
        $users = User::query()->whereIn('id', $sums->keys())->where('stats_opt_out', false)->get()->keyBy('id');
        $name = fn ($id) => (int) $id === 0 ? __('stats.guests') : $users[$id]->displayName();

        return $sums->filter(fn ($beers, $id) => ((int) $id === 0 || $users->has($id)) && $beers > 0)
            ->sortDesc()->take($top)
            ->map(fn ($beers, $id) => ['user_id' => (int) $id, 'name' => $name($id), 'beers' => $beers])
            ->values()->all();
    }
}

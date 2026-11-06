<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Support\StatsPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Backend statistics for kassiers and admins (/admin/statistik, 1.31.0), independent of the user feature switches.
 *
 * Rows come from the same sources and counting rules as Stats::global() (confirmed own consumption, round items
 * at the drinker with the round's time and price, guest sales as GUESTS_ID, round beers without a name as
 * INVITED_ID), plus the gifts ("Bier verschenken", the giver's row) which are credit, not drunk beers: they are
 * only reachable with type "geschenke" and never mixed into "alle". Period, user and type are filtered in SQL;
 * grouping by time bucket or by drinker happens in PHP on the rows of the period.
 *
 * Opt-out users (stats_opt_out) are shown as "Anonym n" (Stats::numberAnonymous()) and cannot be
 * selected individually (assumption 1.31.0: the filter must not reveal them either). Accounts without beer account
 * ("Nur Verwaltung") are merged into one row "Ohne Bierkonto".
 */
final class BackendStats
{
    public const USER_ALL = 'alle';

    public const USER_GUESTS = 'gaeste';

    public const USER_INVITED = 'eingeladen';

    public const TYPE_ALL = 'alle';

    public const TYPE_CONSUMPTION = 'konsum';

    public const TYPE_ROUNDS = 'runden';

    public const TYPE_GUESTS = 'gaeste';

    public const TYPE_GIFTS = 'geschenke';

    /** In the order of the select. */
    public const TYPES = [self::TYPE_ALL, self::TYPE_CONSUMPTION, self::TYPE_ROUNDS, self::TYPE_GUESTS, self::TYPE_GIFTS];

    public const GROUP_TIME = 'zeit';

    public const GROUP_USER = 'benutzer';

    public const GROUPS = [self::GROUP_TIME, self::GROUP_USER];

    public const RASTER_AUTO = 'auto';

    /** Raster => unit of the buckets ("auto" = the period's granularity). */
    public const RASTERS = [self::RASTER_AUTO => null, 'tag' => 'day', 'woche' => 'week', 'monat' => 'month', 'jahr' => 'year'];

    /** Defaults of the GET parameters (left out of links). */
    public const DEFAULTS = ['benutzer' => self::USER_ALL, 'typ' => self::TYPE_ALL, 'gruppe' => self::GROUP_TIME, 'raster' => self::RASTER_AUTO];

    /** Row kinds. */
    public const KIND_CONSUMPTION = 'consumption';

    public const KIND_ROUND = 'round';

    public const KIND_GUEST = 'guest';

    public const KIND_GIFT = 'gift';

    /** Grouping key of the merged accounts without beer account. */
    public const NO_BEER_ACCOUNT = 'ohne';

    /** Time tables up to this many rows get a chart. */
    public const CHART_MAX_ROWS = 62;

    /**
     * Normalised filters from the GET parameters; anything unknown falls back to the default (never an error).
     * A user id is only accepted when the user is selectable (see selectableUsers()).
     *
     * @param  array<string, mixed>  $query
     * @return array{benutzer:string, typ:string, gruppe:string, raster:string}
     */
    public static function filters(array $query): array
    {
        $filters = self::DEFAULTS;
        $user = $query['benutzer'] ?? null;
        if (in_array($user, [self::USER_GUESTS, self::USER_INVITED], true)) {
            $filters['benutzer'] = $user;
        } elseif (is_string($user) && preg_match('/^[1-9]\d{0,9}$/', $user) === 1
            && self::selectableQuery()->whereKey((int) $user)->exists()) {
            $filters['benutzer'] = $user;
        }
        foreach (['typ' => self::TYPES, 'gruppe' => self::GROUPS, 'raster' => array_keys(self::RASTERS)] as $key => $allowed) {
            if (in_array($query[$key] ?? null, $allowed, true)) {
                $filters[$key] = $query[$key];
            }
        }

        return $filters;
    }

    /**
     * GET parameters of the filters that differ from the defaults (for links next to the period's query()).
     *
     * @param  array{benutzer:string, typ:string, gruppe:string, raster:string}  $filters
     * @return array<string, string>
     */
    public static function query(array $filters): array
    {
        $query = array_diff_assoc($filters, self::DEFAULTS);
        if ($filters['gruppe'] !== self::GROUP_TIME) {
            unset($query['raster']);
        }

        return $query;
    }

    /**
     * Users that can be chosen in the filter: with beer account and not opted out, active or not, by display name.
     *
     * @return Collection<int, User>
     */
    public static function selectableUsers(): Collection
    {
        return self::selectableQuery()->get()->sortBy(fn (User $u) => mb_strtolower($u->displayName()))->values();
    }

    /**
     * The whole page: totals, comparison with the preceding period and the table rows.
     *
     * @param  array{benutzer:string, typ:string, gruppe:string, raster:string}  $filters
     * @return array{beers:int, amount:int, previous:?array{label:string, beers:int, amount:int, change_beers:?float, change_amount:?float}, rows:list<array<string, mixed>>, table_beers:int, table_amount:int, unit:?string}
     */
    public static function report(StatsPeriod $period, array $filters): array
    {
        $rows = self::rows($period->dbBounds(), $filters);
        $beers = (int) $rows->sum('quantity');
        $amount = (int) -$rows->sum('amount');

        $previous = null;
        if (($before = $period->preceding()) !== null) {
            $old = self::rows($before->dbBounds(), $filters);
            $oldBeers = (int) $old->sum('quantity');
            $oldAmount = (int) -$old->sum('amount');
            $previous = [
                'label' => $before->label(),
                'beers' => $oldBeers,
                'amount' => $oldAmount,
                'change_beers' => self::change($beers, $oldBeers),
                'change_amount' => self::change($amount, $oldAmount),
            ];
        }

        $unit = $filters['gruppe'] === self::GROUP_TIME ? self::unit($period, $filters['raster']) : null;
        $table = $unit !== null ? self::byTime($rows, $period, $unit, $filters['raster'] === self::RASTER_AUTO) : self::byUser($rows);

        return [
            'beers' => $beers,
            'amount' => $amount,
            'previous' => $previous,
            'rows' => $table,
            // Totals row: sum of the rows shown (differs from beers/amount only for "gesamt" with raster "auto",
            // whose table covers the last Stats::MONTHS months).
            'table_beers' => array_sum(array_column($table, 'beers')),
            'table_amount' => array_sum(array_column($table, 'amount')),
            'unit' => $unit,
        ];
    }

    /**
     * Filtered rows of the period (null bounds = all time): objects with user_id, quantity, amount (negative =
     * what the beers cost), created_at and kind. User and type filters are applied in SQL.
     *
     * @param  array{0:string, 1:string}|null  $bounds
     * @param  array{benutzer:string, typ:string, gruppe:string, raster:string}  $filters
     * @return Collection<int, object{user_id:int, quantity:int, amount:int, created_at:CarbonInterface, kind:string}>
     */
    public static function rows(?array $bounds, array $filters): Collection
    {
        $type = $filters['typ'];
        $user = $filters['benutzer'];
        $userId = ctype_digit($user) ? (int) $user : null;
        $named = $user === self::USER_ALL || $userId !== null;
        $rows = collect();

        if ($named && in_array($type, [self::TYPE_ALL, self::TYPE_CONSUMPTION], true)) {
            $rows = $rows->concat(self::kind(self::transactionRows(Stats::consumptions(), $bounds, $userId), self::KIND_CONSUMPTION));
        }
        if (in_array($type, [self::TYPE_ALL, self::TYPE_ROUNDS], true) && $user !== self::USER_GUESTS) {
            $items = match (true) {
                $userId !== null => fn ($q) => $q->where('user_id', $userId),
                $user === self::USER_INVITED => fn ($q) => $q->whereNull('user_id'),
                default => null,
            };
            $rows = $rows->concat(self::kind(Stats::roundRows($bounds, $items), self::KIND_ROUND));
        }
        if (in_array($type, [self::TYPE_ALL, self::TYPE_GUESTS], true) && in_array($user, [self::USER_ALL, self::USER_GUESTS], true)) {
            $rows = $rows->concat(self::kind(Stats::guestRows($bounds), self::KIND_GUEST));
        }
        if ($named && $type === self::TYPE_GIFTS) {
            // The giver's row: quantity = all gifted beers, amount = their CHF value (negative).
            $gifts = Transaction::query()->confirmed()->where('type', Transaction::TYPE_GIFT)->whereNull('parent_id');
            $rows = $rows->concat(self::kind(self::transactionRows($gifts, $bounds, $userId), self::KIND_GIFT));
        }

        return $rows->values();
    }

    /** Change in percent from $old to $new, null when $old is 0. */
    public static function change(int $new, int $old): ?float
    {
        return $old > 0 ? round(($new - $old) / $old * 100, 1) : null;
    }

    /** Unit of the time buckets: "day", "week", "month" or "year" ("auto" = granularity of the period). */
    public static function unit(StatsPeriod $period, string $raster): string
    {
        return self::RASTERS[$raster] ?? $period->granularity();
    }

    /**
     * One row per time bucket of the period (empty buckets included), in order. "gesamt" with "auto" covers the
     * last Stats::MONTHS months like the user page; "gesamt" with a chosen raster runs from the first row to today.
     *
     * @param  Collection<int, object>  $rows
     * @return list<array{key:string, label:string, short:string, beers:int, amount:int}>
     */
    public static function byTime(Collection $rows, StatsPeriod $period, string $unit, bool $auto = true): array
    {
        $today = StatsPeriod::today();
        if (! $period->isAll()) {
            [$start, $end] = [$period->from, $period->to];
        } elseif ($auto) {
            [$start, $end] = [$today->startOfMonth()->subMonthsNoOverflow(Stats::MONTHS - 1), $today];
        } else {
            $first = $rows->min('created_at');
            if ($first === null) {
                return [];
            }
            [$start, $end] = [self::local($first), $today];
        }

        $buckets = [];
        $multiYear = $start->year !== $end->year;
        for ($d = self::align($start, $unit); $d->lessThanOrEqualTo($end); $d = self::step($d, $unit)) {
            $buckets[self::bucketKey($d, $unit)] = ['key' => self::bucketKey($d, $unit)] + self::bucketLabels($d, $unit, $multiYear) + ['beers' => 0, 'amount' => 0];
        }
        foreach ($rows as $row) {
            $key = self::bucketKey(self::local($row->created_at), $unit);
            if (isset($buckets[$key])) {
                $buckets[$key]['beers'] += (int) $row->quantity;
                $buckets[$key]['amount'] += (int) -$row->amount;
            }
        }

        return array_values($buckets);
    }

    /**
     * One row per drinker (all rows, no top-N cut), sorted by beers desc with competition ranks (Stats::ranked()).
     * Opt-out users become "Anonym n" (Stats::numberAnonymous()); accounts without beer account (and unknown ids) are merged
     * into "Ohne Bierkonto"; the pseudo rows "Gäste" and "Gäste (eingeladen)" stay as they are.
     *
     * @param  Collection<int, object>  $rows
     * @return list<array{rank:int, key:string, name:string, beers:int, amount:int, anonymous:bool}>
     */
    public static function byUser(Collection $rows): array
    {
        $ids = $rows->pluck('user_id')->unique()->filter(fn ($id) => (int) $id > 0)->values();
        $users = User::query()->whereIn('id', $ids)->hasBeerAccount()->get()->keyBy('id');
        $pseudo = [Stats::GUESTS_ID => __('stats.guests'), Stats::INVITED_ID => __('stats.guests_invited')];

        $groups = [];
        foreach ($rows as $row) {
            $id = (int) $row->user_id;
            $key = isset($pseudo[$id]) || $users->has($id) ? (string) $id : self::NO_BEER_ACCOUNT;
            $groups[$key] ??= [
                'key' => $key,
                'name' => match (true) {
                    isset($pseudo[$id]) => $pseudo[$id],
                    $users->has($id) => $users[$id]->displayName(),
                    default => __('stats.backend.no_beer_account'),
                },
                'beers' => 0,
                'amount' => 0,
                'anonymous' => $users->has($id) && (bool) $users[$id]->stats_opt_out,
            ];
            $groups[$key]['beers'] += (int) $row->quantity;
            $groups[$key]['amount'] += (int) -$row->amount;
        }

        // Beers desc, then CHF desc, then the key: stable, and independent of the (maybe anonymous) names.
        usort($groups, fn ($a, $b) => [$b['beers'], $b['amount'], $a['key']] <=> [$a['beers'], $a['amount'], $b['key']]);

        return Stats::numberAnonymous(Stats::ranked(collect($groups), 'beers', PHP_INT_MAX));
    }

    private static function selectableQuery(): Builder
    {
        return User::query()->hasBeerAccount()->where('stats_opt_out', false);
    }

    /**
     * @param  array{0:string, 1:string}|null  $bounds
     * @return Collection<int, object>
     */
    private static function transactionRows(Builder $query, ?array $bounds, ?int $userId): Collection
    {
        return $query->when($bounds, fn ($q) => $q->whereBetween('created_at', $bounds))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->get(['user_id', 'quantity', 'amount', 'created_at'])
            ->map(fn (Transaction $t) => (object) ['user_id' => (int) $t->user_id, 'quantity' => (int) $t->quantity, 'amount' => (int) $t->amount, 'created_at' => $t->created_at])
            ->toBase();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private static function kind(Collection $rows, string $kind): Collection
    {
        return $rows->map(function (object $row) use ($kind) {
            $row->kind = $kind;

            return $row;
        });
    }

    private static function align(CarbonImmutable $day, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'day' => $day->startOfDay(),
            'week' => $day->startOfWeek(CarbonInterface::MONDAY)->startOfDay(),
            'month' => $day->startOfMonth()->startOfDay(),
            default => $day->startOfYear()->startOfDay(),
        };
    }

    private static function step(CarbonImmutable $day, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'day' => $day->addDay(),
            'week' => $day->addWeek(),
            'month' => $day->addMonthNoOverflow(),
            default => $day->addYearNoOverflow(),
        };
    }

    private static function bucketKey(CarbonImmutable $day, string $unit): string
    {
        return match ($unit) {
            'day' => $day->format('Y-m-d'),
            // ISO week year and week, e.g. "2026-W45" (the first days of January may belong to the previous year).
            'week' => sprintf('%d-W%02d', $day->isoWeekYear(), $day->isoWeek()),
            'month' => $day->format('Y-m'),
            default => $day->format('Y'),
        };
    }

    /**
     * Table label and short chart label of a bucket.
     *
     * @return array{label:string, short:string}
     */
    private static function bucketLabels(CarbonImmutable $day, string $unit, bool $multiYear): array
    {
        return match ($unit) {
            'day' => ['label' => __('stats.weekday_short.'.$day->dayOfWeekIso).' '.$day->format('j.n.Y'), 'short' => $day->format('j.n.')],
            // "KW 45 (2.–8.11.2026)": the whole ISO week, even when the period cuts it.
            'week' => ['label' => StatsPeriod::week($day)->label(), 'short' => 'KW '.$day->isoWeek()],
            'month' => [
                'label' => __('stats.month_long.'.$day->month).' '.$day->year,
                'short' => __('stats.month_short.'.$day->month).($multiYear ? ' '.$day->format('y') : ''),
            ],
            default => ['label' => (string) $day->year, 'short' => (string) $day->year],
        };
    }

    private static function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone((string) config('app.timezone'));
    }
}

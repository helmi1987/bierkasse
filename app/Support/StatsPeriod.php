<?php

namespace App\Support;

use App\Services\Stats;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Period of the statistics page (1.30.0), built from the GET parameters
 * `zeitraum` (gesamt|woche|monat|quartal|jahr|frei), `von` and `bis` (YYYY-MM-DD, app time zone).
 *
 * - gesamt: all time (no bounds), chart = last Stats::MONTHS months.
 * - woche: ISO week (Monday–Sunday) containing `von`, chart per day.
 * - monat: month containing `von`, chart per day.
 * - quartal: calendar quarter containing `von`, chart per month (1.31.0).
 * - jahr: year of `von`, chart per month.
 * - frei: `von`–`bis` inclusive (default last 30 days, swapped if reversed, at most MAX_YEARS),
 *   chart per day up to DAILY_MAX_DAYS days, else per month.
 *
 * Invalid input never fails: unknown kinds become "gesamt", unparsable dates the defaults.
 * from/to are the first and last moment of the period in the app time zone.
 */
final class StatsPeriod
{
    public const ALL = 'gesamt';

    public const WEEK = 'woche';

    public const MONTH = 'monat';

    public const QUARTER = 'quartal';

    public const YEAR = 'jahr';

    public const CUSTOM = 'frei';

    /** In the order of the tabs. */
    public const KINDS = [self::ALL, self::WEEK, self::MONTH, self::QUARTER, self::YEAR, self::CUSTOM];

    /** Custom periods up to this many days are charted per day, longer ones per month. */
    public const DAILY_MAX_DAYS = 62;

    /** Longest custom period. */
    public const MAX_YEARS = 5;

    /** Length of the default custom period (ending today). */
    public const CUSTOM_DEFAULT_DAYS = 30;

    private function __construct(
        public readonly string $kind,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
    ) {}

    /**
     * @param  array<string, mixed>  $query  Usually $request->query().
     */
    public static function fromQuery(array $query): self
    {
        $kind = in_array($query['zeitraum'] ?? null, self::KINDS, true) ? $query['zeitraum'] : self::ALL;
        $von = self::parseDate($query['von'] ?? null);

        return match ($kind) {
            self::WEEK => self::week($von ?? self::today()),
            self::MONTH => self::month($von ?? self::today()),
            self::QUARTER => self::quarter($von ?? self::today()),
            self::YEAR => self::year($von ?? self::today()),
            self::CUSTOM => self::custom($von, self::parseDate($query['bis'] ?? null)),
            default => self::all(),
        };
    }

    public static function all(): self
    {
        return new self(self::ALL, null, null);
    }

    public static function week(CarbonInterface $day): self
    {
        $start = self::local($day)->startOfWeek(CarbonInterface::MONDAY)->startOfDay();

        return new self(self::WEEK, $start, $start->addDays(6)->endOfDay());
    }

    public static function month(CarbonInterface $day): self
    {
        $start = self::local($day)->startOfMonth()->startOfDay();

        return new self(self::MONTH, $start, $start->endOfMonth()->endOfDay());
    }

    public static function quarter(CarbonInterface $day): self
    {
        $start = self::local($day)->firstOfQuarter()->startOfDay();

        return new self(self::QUARTER, $start, $start->lastOfQuarter()->endOfDay());
    }

    public static function year(CarbonInterface $day): self
    {
        $start = self::local($day)->startOfYear()->startOfDay();

        return new self(self::YEAR, $start, $start->endOfYear()->endOfDay());
    }

    /** Missing bounds: `bis` defaults to today, `von` to CUSTOM_DEFAULT_DAYS days before `bis`. */
    public static function custom(?CarbonInterface $from, ?CarbonInterface $to): self
    {
        $to = $to !== null ? self::local($to)->startOfDay() : self::today();
        $from = $from !== null ? self::local($from)->startOfDay() : $to->subDays(self::CUSTOM_DEFAULT_DAYS - 1);
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }
        $earliest = $to->subYearsNoOverflow(self::MAX_YEARS)->addDay();
        if ($from->lessThan($earliest)) {
            $from = $earliest;
        }

        return new self(self::CUSTOM, $from, $to->endOfDay());
    }

    public function isAll(): bool
    {
        return $this->kind === self::ALL;
    }

    /** True for the kinds with previous/next navigation (woche, monat, quartal, jahr). */
    public function isStepped(): bool
    {
        return in_array($this->kind, [self::WEEK, self::MONTH, self::QUARTER, self::YEAR], true);
    }

    /** Heading of the period, e.g. "KW 45 (2.–8.11.2026)", "November 2026", "Q4 2026", "2026", "2.11.2026 – 6.11.2026". */
    public function label(): string
    {
        return match ($this->kind) {
            self::WEEK => __('stats.period.week_label', ['week' => $this->from->isoWeek(), 'range' => $this->weekRange()]),
            self::MONTH => __('stats.month_long.'.$this->from->month).' '.$this->from->year,
            self::QUARTER => 'Q'.$this->from->quarter.' '.$this->from->year,
            self::YEAR => (string) $this->from->year,
            self::CUSTOM => $this->from->format('j.n.Y').' – '.$this->to->format('j.n.Y'),
            default => __('stats.period.'.self::ALL),
        };
    }

    public function previous(): ?self
    {
        return match ($this->kind) {
            self::WEEK => self::week($this->from->subWeek()),
            self::MONTH => self::month($this->from->subMonthNoOverflow()),
            self::QUARTER => self::quarter($this->from->subMonthsNoOverflow(3)),
            self::YEAR => self::year($this->from->subYearNoOverflow()),
            default => null,
        };
    }

    /** The following period, or null when it would start after today. */
    public function next(): ?self
    {
        $next = match ($this->kind) {
            self::WEEK => self::week($this->from->addWeek()),
            self::MONTH => self::month($this->from->addMonthNoOverflow()),
            self::QUARTER => self::quarter($this->from->addMonthsNoOverflow(3)),
            self::YEAR => self::year($this->from->addYearNoOverflow()),
            default => null,
        };

        return $next !== null && $next->from->lessThanOrEqualTo(self::today()) ? $next : null;
    }

    /**
     * Period of the same length immediately before this one (backend comparison, 1.31.0): previous() for the
     * stepped kinds, the same number of days before `von` for "frei", null for "gesamt".
     */
    public function preceding(): ?self
    {
        if ($this->kind === self::CUSTOM) {
            $to = $this->from->subDay();

            return self::custom($to->subDays($this->days() - 1), $to);
        }

        return $this->previous();
    }

    /**
     * GET parameters that select this period again (for links; empty for "gesamt").
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return match ($this->kind) {
            self::ALL => [],
            self::CUSTOM => ['zeitraum' => $this->kind, 'von' => $this->from->format('Y-m-d'), 'bis' => $this->to->format('Y-m-d')],
            default => ['zeitraum' => $this->kind, 'von' => $this->from->format('Y-m-d')],
        };
    }

    /**
     * Bounds for `whereBetween('created_at', …)`, or null for "gesamt". Eloquent writes timestamps as
     * wall-clock text in the PHP default time zone (set from app.timezone), so the bounds are converted to
     * that zone before formatting; with app.timezone = Europe/Zurich this keeps local midnight exact.
     *
     * @return array{0:string, 1:string}|null
     */
    public function dbBounds(): ?array
    {
        if ($this->from === null) {
            return null;
        }

        return [self::toDb($this->from), self::toDb($this->to)];
    }

    /** Formats a moment like Eloquent stores created_at (see dbBounds()). */
    public static function toDb(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s');
    }

    /** "day" or "month": granularity of the chart. */
    public function granularity(): string
    {
        return match ($this->kind) {
            self::WEEK, self::MONTH => 'day',
            self::CUSTOM => $this->days() <= self::DAILY_MAX_DAYS ? 'day' : 'month',
            default => 'month',
        };
    }

    /**
     * Chart buckets of the period in order: key (see bucketKey()) => label.
     *
     * @return array<string, string>
     */
    public function buckets(): array
    {
        if ($this->isAll()) {
            $start = self::today()->startOfMonth()->subMonthsNoOverflow(Stats::MONTHS - 1);
            $end = self::today()->startOfMonth();
        } else {
            $start = $this->granularity() === 'day' ? $this->from : $this->from->startOfMonth();
            $end = $this->to->startOfDay();
        }

        $buckets = [];
        if ($this->granularity() === 'day') {
            for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
                $buckets[$d->format('Y-m-d')] = match ($this->kind) {
                    self::WEEK => __('stats.weekday_short.'.$d->dayOfWeekIso).' '.$d->format('j.n.'),
                    self::MONTH => $d->format('j'),
                    default => $d->format('j.n.'),
                };
            }
        } else {
            for ($m = $start; $m->lessThanOrEqualTo($end); $m = $m->addMonthNoOverflow()) {
                $buckets[$m->format('Y-m')] = in_array($this->kind, [self::YEAR, self::QUARTER], true)
                    ? __('stats.month_short.'.$m->month)
                    : __('stats.month_short.'.$m->month).' '.$m->format('y');
            }
        }

        return $buckets;
    }

    /** Key of the chart bucket a moment falls into (app time zone). */
    public function bucketKey(CarbonInterface $moment): string
    {
        return self::local($moment)->format($this->granularity() === 'day' ? 'Y-m-d' : 'Y-m');
    }

    /** Title of the course chart (SVG title and aria-label). */
    public function chartTitle(): string
    {
        if ($this->isAll()) {
            return __('stats.chart_months');
        }

        return __($this->granularity() === 'day' ? 'stats.chart_course_days' : 'stats.chart_course_months', ['period' => $this->label()]);
    }

    /** Today, start of day, in the app time zone. */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz())->startOfDay();
    }

    /** Strict YYYY-MM-DD in the app time zone, or null (no exceptions, no overflow like 2026-02-31). */
    public static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::tz());
        } catch (Throwable) {
            return null;
        }
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value || $date->year < 1970) {
            return null;
        }

        return $date;
    }

    /** Number of calendar days of a bounded period (rounded: DST days are 23 or 25 hours long). */
    public function days(): int
    {
        return $this->from === null ? 0 : (int) round($this->from->diffInDays($this->to->startOfDay())) + 1;
    }

    private function weekRange(): string
    {
        $from = $this->from;
        $to = $this->to;
        $start = match (true) {
            $from->month === $to->month && $from->year === $to->year => $from->format('j.'),
            $from->year === $to->year => $from->format('j.n.'),
            default => $from->format('j.n.Y'),
        };

        return $start.'–'.$to->format('j.n.Y');
    }

    private static function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::tz());
    }

    private static function tz(): string
    {
        return (string) config('app.timezone');
    }
}

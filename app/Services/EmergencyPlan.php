<?php

namespace App\Services;

use App\Mail\KassierNotice;
use App\Models\EmergencyPlanPrint;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Offline emergency plan (1.40.0, admins): a printed tally sheet in a sealed envelope for when the app is down.
 *
 * - List: active users with a beer account (the same set that can book beers), sorted by name, no balances.
 * - Print ("Notfallplan drucken"): stored as EmergencyPlanPrint (time, admin, user IDs) with audit
 *   "emergency_plan.print"; the test print (page 1 only, window frame visible) stores nothing.
 * - Difference since the last print: users active now but not on it (new) plus users on it that are no longer
 *   on the list (left, locked, deleted or "Nur Verwaltung" now). Never printed: every listed user is new.
 * - Reminder mail (`emergency-plan:reminders`, daily): difference > threshold, to the chosen admins/kassiers,
 *   at most once a week (EMERGENCY_MAIL_SENT_AT); stops once a new print brings the difference down.
 */
class EmergencyPlan
{
    /** Layout of the list pages in mm (A4 minus the @page margin; the CSS of the print view uses the same values). */
    public const PAGE_MM = 276.0;

    /** Fold line of the sheet (A4 folded once for a C5 envelope), in mm from the sheet top. */
    public const FOLD_MM = 148.5;

    public const HEAD_MM = 26.0;

    public const FOOT_MM = 7.0;

    /** Frame borders and rounding reserve. */
    public const FRAME_MM = 2.0;

    public const ROW_MM = 13.5;

    public const SEP_MM = 6.5;

    /** Fields per row and boxes per field (divider after BOXES_GROUP). */
    public const COLUMNS = 3;

    public const BOXES = 10;

    public const BOXES_GROUP = 5;

    /** Days between two reminder mails (calendar days). */
    public const MAIL_INTERVAL_DAYS = 7;

    /**
     * Users on the list: active, with a beer account, sorted by name.
     *
     * @return Collection<int, User>
     */
    public static function users(): Collection
    {
        return self::sorted(User::query()->where('status', User::STATUS_ACTIVE)->hasBeerAccount()->get());
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private static function sorted(Collection $users): Collection
    {
        return $users->sortBy(fn (User $u) => mb_strtolower($u->displayName()).' '.mb_strtolower((string) $u->username))->values();
    }

    /**
     * Joined and left users since the given print (null = never printed: every listed user is new).
     *
     * @return array{new: Collection<int, User>, left: Collection<int, User>, left_unknown: int, count: int}
     */
    public static function difference(?EmergencyPlanPrint $print = null, bool $useLatest = true): array
    {
        if ($print === null && $useLatest) {
            $print = EmergencyPlanPrint::latestPrint();
        }
        $current = self::users();
        $printed = $print?->userIds() ?? [];
        $currentIds = $current->pluck('id')->map(fn ($id) => (int) $id)->all();

        $new = $current->reject(fn (User $u) => in_array((int) $u->id, $printed, true))->values();
        $leftIds = array_values(array_diff($printed, $currentIds));
        $left = $leftIds === [] ? collect() : self::sorted(User::query()->whereKey($leftIds)->get());
        // Deleted accounts still count, without a name.
        $unknown = count($leftIds) - $left->count();

        return ['new' => $new, 'left' => $left, 'left_unknown' => $unknown, 'count' => $new->count() + count($leftIds)];
    }

    /**
     * Contacts for page 1 and the short guide: active kassiers and admins (incl. "Nur Verwaltung"), each person
     * once (by account), sorted by name.
     *
     * @return array{kassier: list<string>, admin: list<string>}
     */
    public static function contacts(): array
    {
        $staff = self::sorted(User::query()->where('status', User::STATUS_ACTIVE)
            ->whereIn('role', [User::ROLE_KASSIER, User::ROLE_ADMIN])->get())->unique('id');

        return [
            'kassier' => $staff->where('role', User::ROLE_KASSIER)->map(fn (User $u) => $u->displayName())->values()->all(),
            'admin' => $staff->where('role', User::ROLE_ADMIN)->map(fn (User $u) => $u->displayName())->values()->all(),
        ];
    }

    /** Stores a real print of the current list (with audit). */
    public static function record(User $by): EmergencyPlanPrint
    {
        $ids = self::users()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return DB::transaction(function () use ($by, $ids) {
            $before = self::difference();
            $print = EmergencyPlanPrint::query()->create([
                'printed_by' => $by->id,
                'printed_at' => now(),
                'user_ids' => $ids,
                'user_count' => count($ids),
            ]);
            Audit::log('emergency_plan.print', $print, [], [
                'user_count' => count($ids),
                'new_users' => $before['new']->count(),
                'left_users' => $before['count'] - $before['new']->count(),
            ], $by);

            return $print;
        });
    }

    /**
     * View data of the print view: a stored print (its users and time) or a test print (current users, now,
     * page 1 only).
     *
     * @return array<string, mixed>
     */
    public static function sheet(?EmergencyPlanPrint $print, User $viewer): array
    {
        $test = $print === null;
        if ($test) {
            $users = self::users();
            $at = now();
            $person = $viewer->displayName();
        } else {
            $users = self::sorted(User::query()->whereKey($print->userIds())->get());
            $at = $print->printed_at;
            $person = $print->printer?->displayName() ?? '?';
        }
        $contacts = self::contacts();
        $vars = [
            '{bierpreis}' => Money::plain(AppSettings::beerPrice()),
            '{datum}' => local_time($at, 'd.m.Y'),
            '{person}' => $person,
            '{anzahl}' => (string) $users->count(),
            '{kassier}' => $contacts['kassier'] !== [] ? implode(', ', $contacts['kassier']) : '–',
            '{admin}' => $contacts['admin'] !== [] ? implode(', ', $contacts['admin']) : '–',
        ];
        $pages = self::pages($users->all());
        $texts = [];
        foreach (AppSettings::EMERGENCY_TEXT_KEYS as $key) {
            $texts[$key] = AppSettings::emergencyText($key);
        }

        return [
            'test' => $test,
            'print' => $print,
            'users' => $users,
            'printedAt' => local_time($at),
            'date' => local_time($at, 'd.m.Y'),
            'person' => $person,
            'price' => Money::format(AppSettings::beerPrice()),
            'appName' => AppSettings::appName(),
            'vars' => $vars,
            'texts' => $texts,
            'steps' => array_values(array_filter(array_map('trim', preg_split('/\R/', $texts['steps']) ?: []), fn ($l) => $l !== '')),
            'footer' => array_values(array_filter(array_map('trim', preg_split('/\R/', $texts['footer']) ?: []), fn ($l) => $l !== '')),
            'pages' => $test ? [] : $pages,
            'totalPages' => 1 + count($pages),
            'window' => self::windowCss(AppSettings::emergencyWindow()),
        ];
    }

    /**
     * Text with the placeholders replaced, escaped; "**text**" becomes bold.
     *
     * @param  array<string, string>  $vars
     */
    public static function html(string $text, array $vars): HtmlString
    {
        $escaped = e(strtr($text, $vars));

        return new HtmlString((string) preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $escaped));
    }

    /**
     * Positions of the window and the text below it on page 1, in mm inside the page box (the sheet minus the
     * print margin).
     *
     * @param  array{side: string, top: int, edge: int, width: int, height: int}  $w
     * @return array{side: string, top: float, edge: float, width: float, height: float, body: float, body_x: float}
     */
    public static function windowCss(array $w): array
    {
        $m = AppSettings::EMERGENCY_PRINT_MARGIN;

        return [
            'side' => $w['side'] === 'left' ? 'left' : 'right',
            'top' => max(0, $w['top'] - $m),
            'edge' => max(0, $w['edge'] - $m),
            'width' => $w['width'],
            'height' => $w['height'],
            // Text block in the lower half (1.40.3): below the fold of the C5 envelope (A4 folded once), so that only
            // the window is visible when folded; lower when the window reaches further.
            'body' => max(self::FOLD_MM + 10, $w['top'] + $w['height'] + 10) - $m,
            // Side margin of the text block: the window's distance from the sheet edge, on both sides.
            'body_x' => max(0, $w['edge'] - $m),
        ];
    }

    /**
     * Pages 2+ of the plan: rows of COLUMNS fields per page; after the users the bar "Neue Benutzer" and blank
     * fields up to the end of the last page. Every page holds the same number of rows (fixed heights).
     *
     * @param  list<User>  $users
     * @return list<list<array{type: string, cells?: list<User|null>}>>
     */
    public static function pages(array $users): array
    {
        $available = self::PAGE_MM - self::HEAD_MM - self::FOOT_MM - self::FRAME_MM;
        $perPage = (int) floor($available / self::ROW_MM);
        $rows = array_map(fn (array $cells) => ['type' => 'users', 'cells' => array_pad($cells, self::COLUMNS, null)], array_chunk($users, self::COLUMNS));

        $pages = array_chunk($rows, $perPage);
        $last = array_pop($pages) ?? [];
        $used = count($last) * self::ROW_MM;
        if ($used + self::SEP_MM + self::ROW_MM > $available) {
            $pages[] = $last;
            $last = [];
            $used = 0.0;
        }
        $last[] = ['type' => 'sep'];
        $blank = (int) floor(($available - $used - self::SEP_MM) / self::ROW_MM);
        for ($i = 0; $i < $blank; $i++) {
            $last[] = ['type' => 'blank'];
        }
        $pages[] = $last;

        return $pages;
    }

    /**
     * Reminder mail (daily via `emergency-plan:reminders`): sent when the difference is above the threshold,
     * recipients are chosen and the last mail is at least MAIL_INTERVAL_DAYS calendar days ago.
     *
     * @return array{due: bool, reason: string, count: int, recipients: int, sent: int}
     */
    public static function remind(bool $dryRun = false): array
    {
        $diff = self::difference();
        $threshold = AppSettings::emergencyMailThreshold();
        $result = ['due' => false, 'reason' => 'below_threshold', 'count' => $diff['count'], 'recipients' => 0, 'sent' => 0];
        if ($diff['count'] <= $threshold) {
            return $result;
        }
        $sentAt = AppSettings::emergencyMailSentAt();
        if ($sentAt !== null && $sentAt->copy()->setTimezone(config('app.timezone'))->startOfDay()->addDays(self::MAIL_INTERVAL_DAYS)->isFuture()) {
            return ['reason' => 'interval'] + $result;
        }
        $recipients = self::recipientAddresses();
        $result = ['due' => true, 'reason' => $recipients === [] ? 'no_recipients' : 'due', 'recipients' => count($recipients)] + $result;
        if ($dryRun || $recipients === []) {
            return $result;
        }

        $sent = self::send($diff, $threshold, $recipients);
        if ($sent > 0) {
            Setting::put(AppSettings::EMERGENCY_MAIL_SENT_AT, now()->utc()->format(DATE_ATOM));
            Audit::log('emergency_plan.reminder', null, [], ['difference' => $diff['count'], 'recipients' => $sent], null, 'cli');
        }

        return ['sent' => $sent] + $result;
    }

    /**
     * Admins and kassiers that can be chosen as recipients (active), sorted by name.
     *
     * @return Collection<int, User>
     */
    public static function recipientCandidates(): Collection
    {
        return self::sorted(User::query()->where('status', User::STATUS_ACTIVE)
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_KASSIER])->get());
    }

    /**
     * Addresses of the chosen recipients that are still active admins/kassiers with an address.
     *
     * @return list<string>
     */
    public static function recipientAddresses(): array
    {
        $ids = AppSettings::emergencyMailRecipients();
        if ($ids === []) {
            return [];
        }

        return User::query()->whereKey($ids)
            ->where('status', User::STATUS_ACTIVE)
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_KASSIER])
            ->whereNotNull('email')->where('email', '!=', '')
            ->pluck('email')->unique()->values()->all();
    }

    /**
     * @param  array{new: Collection<int, User>, left: Collection<int, User>, left_unknown: int, count: int}  $diff
     * @param  list<string>  $recipients
     */
    private static function send(array $diff, int $threshold, array $recipients): int
    {
        $last = EmergencyPlanPrint::latestPrint();
        $names = fn (Collection $users) => $users->map(fn (User $u) => $u->displayName())->implode(', ');
        $leftNames = $names($diff['left']);
        if ($diff['left_unknown'] > 0) {
            $leftNames = trim($leftNames.($leftNames !== '' ? ', ' : '').__('emergency.deleted_users', ['count' => $diff['left_unknown']]));
        }
        $subject = trans_choice('mail.emergency_subject', $diff['count'], ['count' => $diff['count']]);
        $lines = [
            __('mail.emergency_line', ['count' => $diff['count'], 'threshold' => $threshold]),
            __('mail.emergency_line_print'),
        ];
        $rows = [
            __('mail.emergency_row_last') => $last !== null
                ? local_time($last->printed_at).' · '.($last->printer?->displayName() ?? '?')
                : __('emergency.never'),
            __('mail.emergency_row_new') => $names($diff['new']),
            __('mail.emergency_row_left') => $leftNames,
        ];
        $url = route('backend.emergency');

        MailSettings::apply();
        $sent = 0;
        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new KassierNotice($subject, $lines, $url, __('mail.emergency_button'), $rows, __('mail.emergency_title'), [__('mail.emergency_outro')]));
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Emergency plan reminder failed', ['to' => $email, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }
}

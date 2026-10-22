<?php

namespace App\Support;

use App\Models\User;

/**
 * Split of the /backend pages in the top bar: "Kassier" (kassiers and admins) and "Backend" (admins only),
 * and the order of their tabs: default set by the admin (setting NAV_ORDER), personal order per
 * account (users.nav_order), else the built-in order.
 */
class BackendNav
{
    public const KASSIER = 'kassier';

    public const BACKEND = 'backend';

    /** Tabs per group in the built-in order: key => [route, active route pattern(s)]. */
    public const ITEMS = [
        self::KASSIER => [
            'dashboard' => ['backend.dashboard', 'backend.dashboard'],
            'cash' => ['backend.cash', 'backend.cash'],
            'stock' => ['backend.stock', 'backend.stock*'],
            'rounds' => ['backend.rounds', 'backend.rounds*'],
            'guests' => ['backend.guests', 'backend.guests*'],
            'invitations' => ['backend.invitations', 'backend.invitations*'],
            'gifts' => ['backend.gifts', 'backend.gifts*'],
            'stats' => ['backend.stats', 'backend.stats'],
            'users' => ['backend.users.index', 'backend.users.*'],
            // QR codes: view only (also for admins); managed under "Backend".
            'qr' => ['backend.qr-codes.index', ['backend.qr-codes.index', 'backend.registration.qr', 'backend.registration.flyer-svg']],
            'deposit_suggestions' => ['backend.deposit-suggestions', 'backend.deposit-suggestions'],
            'audit' => ['backend.audit', ['backend.audit', 'backend.audit.*']],
        ],
        self::BACKEND => [
            'settings' => ['backend.settings', 'backend.settings'],
            'feedback' => ['backend.feedback', 'backend.feedback*'],
            'sso' => ['backend.sso.index', 'backend.sso.*'],
            'nav' => ['backend.nav', 'backend.nav'],
            'qr_admin' => ['backend.qr-admin', 'backend.qr-admin*'],
            'audit_admin' => ['backend.audit-admin', 'backend.audit-admin*'],
            'maintenance' => ['backend.maintenance', 'backend.maintenance*'],
        ],
    ];

    /** Route patterns of the admin-only pages. */
    public const ADMIN_ROUTES = ['backend.settings*', 'backend.nav*', 'backend.feedback*', 'backend.maintenance*', 'backend.sso.*', 'backend.qr-admin*', 'backend.audit-admin*'];

    public static function isAdminPage(): bool
    {
        return request()->routeIs(...self::ADMIN_ROUTES);
    }

    public static function isKassierPage(): bool
    {
        return request()->routeIs('backend.*') && ! self::isAdminPage();
    }

    /** Groups an account can order: kassiers only "Kassier", admins both. */
    public static function groups(User $user): array
    {
        return $user->isAdmin() ? [self::KASSIER, self::BACKEND] : [self::KASSIER];
    }

    /**
     * Default order set by the admin (complete and valid).
     *
     * @return array<string, list<string>>
     */
    public static function defaults(): array
    {
        $stored = (array) AppSettings::get(AppSettings::NAV_ORDER, []);

        return array_map(fn (string $group) => self::normalize($group, $stored[$group] ?? null), array_combine(array_keys(self::ITEMS), array_keys(self::ITEMS)));
    }

    /**
     * Tab keys of a group in the order of the account (personal, else default).
     *
     * @return list<string>
     */
    public static function order(string $group, ?User $user): array
    {
        $personal = (array) ($user?->nav_order ?? []);

        return isset($personal[$group]) ? self::normalize($group, $personal[$group]) : self::defaults()[$group];
    }

    /**
     * Known keys of the group in the given order; unknown keys dropped, missing ones (new tabs) appended.
     *
     * @return list<string>
     */
    public static function normalize(string $group, mixed $keys): array
    {
        $all = array_keys(self::ITEMS[$group]);
        $keys = array_values(array_unique(array_filter((array) $keys, fn ($k) => is_string($k) && in_array($k, $all, true))));

        return array_merge($keys, array_values(array_diff($all, $keys)));
    }
}

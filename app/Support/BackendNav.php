<?php

namespace App\Support;

use App\Models\User;

/**
 * Split of the admin area (/admin) in the top bar: "Kassier" (kassiers and admins) and "Admin" (admins only;
 * internal group key "backend", kept so stored orders stay valid), and the order of their tabs: default set by
 * the admin (setting NAV_ORDER), personal order per account (users.nav_order), else the built-in order.
 * Entscheid 2026-10-24 (1.20.0): Kassier = Übersicht, Kasse, Lager, Benutzer, Buchungen, QR-Codes, Protokoll;
 * Admin = Einstellungen, Feedback, SSO, Navigation, Wartung.
 */
class BackendNav
{
    public const KASSIER = 'kassier';

    /** Group "Admin" (key unchanged since the area was called "Backend"). */
    public const BACKEND = 'backend';

    /** Tabs per group in the built-in order: key => [route, active route pattern(s)]. */
    public const ITEMS = [
        self::KASSIER => [
            'dashboard' => ['backend.dashboard', 'backend.dashboard'],
            // Cash book with the tab "Einzahlungsvorschläge".
            'cash' => ['backend.cash', 'backend.cash*'],
            'stock' => ['backend.stock', 'backend.stock*'],
            // Users with the tab "Einladungen".
            'users' => ['backend.users.index', 'backend.users.*'],
            // Rounds, gifts and guest sales.
            'bookings' => ['backend.bookings', 'backend.bookings*'],
            // QR codes: view for kassiers, management parts for admins, and the print pages.
            'qr' => ['backend.qr-codes.index', ['backend.qr-codes.*', 'backend.registration.qr', 'backend.registration.flyer-svg', 'backend.guests.qr']],
            // Audit log with the tab "Fassungen".
            'audit' => ['backend.audit', ['backend.audit', 'backend.audit.*']],
        ],
        self::BACKEND => [
            'settings' => ['backend.settings', 'backend.settings'],
            'feedback' => ['backend.feedback', 'backend.feedback*'],
            'sso' => ['backend.sso.index', 'backend.sso.*'],
            'nav' => ['backend.nav', 'backend.nav'],
            'maintenance' => ['backend.maintenance', 'backend.maintenance*'],
        ],
    ];

    /**
     * Tabs merged into a new one in 1.20.0: a stored order keeps the new tab at the place of the first
     * old one. All other removed keys are dropped, new keys are appended (see normalize()).
     */
    public const MERGED = [
        self::KASSIER => ['rounds' => 'bookings', 'guests' => 'bookings', 'gifts' => 'bookings'],
        self::BACKEND => [],
    ];

    /** Route patterns of the admin-only pages. */
    public const ADMIN_ROUTES = ['backend.settings*', 'backend.nav*', 'backend.feedback*', 'backend.maintenance*', 'backend.sso.*'];

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
     * Known keys of the group in the given order; merged keys mapped to their new tab, unknown keys dropped,
     * missing ones (new tabs) appended in the built-in order.
     *
     * @return list<string>
     */
    public static function normalize(string $group, mixed $keys): array
    {
        $all = array_keys(self::ITEMS[$group]);
        $merged = self::MERGED[$group] ?? [];
        $keys = array_map(fn ($k) => is_string($k) ? ($merged[$k] ?? $k) : $k, (array) $keys);
        $keys = array_values(array_unique(array_filter($keys, fn ($k) => is_string($k) && in_array($k, $all, true))));

        return array_merge($keys, array_values(array_diff($all, $keys)));
    }
}

<?php

namespace App\Support;

/**
 * Split of the /backend pages in the top bar: "Kassier" (kassiers and admins) and "Backend" (admins only).
 */
class BackendNav
{
    /** Route patterns of the admin-only pages. */
    public const ADMIN_ROUTES = ['backend.settings*', 'backend.registration.*', 'backend.feedback*', 'backend.maintenance*', 'backend.sso.*'];

    public static function isAdminPage(): bool
    {
        return request()->routeIs(...self::ADMIN_ROUTES);
    }

    public static function isKassierPage(): bool
    {
        return request()->routeIs('backend.*') && ! self::isAdminPage();
    }
}

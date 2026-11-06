<?php

namespace App\Support;

/**
 * Which entry of the top navigation is the current page (layout partial partials/top-nav, Livewire component
 * App\Livewire\TopNav). Computed once from the page request: a Livewire update request has no page route.
 */
final class TopNav
{
    public static function activeKey(): ?string
    {
        $request = request();

        return match (true) {
            $request->routeIs('account.dashboard', 'account.history') => 'account',
            $request->routeIs('account.invite*') => 'invite',
            $request->routeIs('stats') => 'stats',
            $request->routeIs('account.settings', 'account.leave') => 'settings',
            BackendNav::isAdminPage() => 'backend',
            BackendNav::isKassierPage() => 'kassier',
            default => null,
        };
    }
}

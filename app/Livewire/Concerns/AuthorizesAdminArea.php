<?php

namespace App\Livewire\Concerns;

/**
 * Livewire actions are public endpoints: every request to a component (first render and each update)
 * re-checks that the logged-in account is a kassier or admin; admin-only actions call authorizeAdmin().
 * The update route itself also runs the admin middleware (routes/web.php).
 */
trait AuthorizesAdminArea
{
    public function bootAuthorizesAdminArea(): void
    {
        abort_unless((bool) auth()->user()?->isKassier(), 403);
    }

    protected function authorizeAdmin(): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403);
    }

    protected function isAdmin(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }
}

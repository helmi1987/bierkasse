<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Money and beer routes need a beer account. "Nur Verwaltung" accounts (admin_only) have none:
 * pages redirect to the backend, actions (POST/PUT/DELETE) are refused with 403.
 */
class EnsureBeerAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasBeerAccount()) {
            abort_unless($request->isMethodSafe(), 403, __('account.admin_only_blocked'));

            $redirect = redirect()->route('backend.dashboard');

            return $request->routeIs('account.dashboard') ? $redirect : $redirect->with('warning', __('account.admin_only_blocked'));
        }

        return $next($request);
    }
}

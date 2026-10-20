<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * After "auth": accounts that left are signed out immediately; a password set by a
 * kassier/admin with "must change" only lets the user reach the password form.
 */
class EnsureActiveUser
{
    /** Routes reachable while a password change is pending. */
    public const PASSWORD_ROUTES = ['account.settings', 'account.password', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['username' => __('auth.inactive')]);
        }

        if ($user->must_change_password && ! $request->routeIs(...self::PASSWORD_ROUTES)) {
            return redirect()->route('account.settings')->with('warning', __('auth.must_change_password'));
        }

        return $next($request);
    }
}

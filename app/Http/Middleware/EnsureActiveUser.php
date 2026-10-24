<?php

namespace App\Http\Middleware;

use App\Support\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * After "auth":
 *  1. accounts that left or were locked are signed out immediately;
 *  2. a password set by a kassier/admin with "must change" only lets the user reach the password form
 *     (the consent page comes after the password change);
 *  3. a user who has not accepted the current privacy policy / terms of use only reaches the consent
 *     page (plus logout and the public legal pages, which are outside this middleware).
 * Like 2, live refresh requests are redirected too (public/js/live.js treats redirects as "reload").
 */
class EnsureActiveUser
{
    /** Routes reachable while a password change is pending. */
    public const PASSWORD_ROUTES = ['account.settings', 'account.password', 'logout'];

    /** Routes reachable while the consent is missing. */
    public const CONSENT_ROUTES = ['consent.show', 'consent.store', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            $locked = $user?->isLocked() ?? false;
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['username' => $locked
                ? __('auth.locked', ['email' => AppSettings::legalEmail()])
                : __('auth.inactive')]);
        }

        if ($user->must_change_password) {
            if (! $request->routeIs(...self::PASSWORD_ROUTES)) {
                return redirect()->route('account.settings')->with('warning', __('auth.must_change_password'));
            }

            // Password first, consent afterwards.
            return $next($request);
        }

        if ($user->needsConsent() && ! $request->routeIs(...self::CONSENT_ROUTES)) {
            // Back to the requested page after accepting (plain page views only).
            $remember = $request->isMethod('GET') && ! $request->hasHeader('X-Live-Refresh') && ! $request->expectsJson();

            return $remember ? redirect()->guest(route('consent.show')) : redirect()->route('consent.show');
        }

        return $next($request);
    }
}

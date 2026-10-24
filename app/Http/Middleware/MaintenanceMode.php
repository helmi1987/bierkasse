<?php

namespace App\Http\Middleware;

use App\Support\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maintenance mode for users (setting in the backend, not "artisan down"):
 * only admins can use the app. Guests only reach the login pages (so an admin can
 * sign in); a signed-in non-admin is signed out and gets the maintenance page.
 */
class MaintenanceMode
{
    /** Routes guests can still reach (login incl. second factor, SSO, password reset, legal texts). */
    public const GUEST_ROUTES = [
        'login', 'login.post', 'login.totp', 'login.totp.verify', 'login.totp.cancel', 'logout',
        'login.sso', 'login.sso.callback',
        'login.personal', 'login.personal.post', 'login.personal.user', 'login.personal.start',
        'password.request', 'password.email', 'password.reset', 'password.update',
        'legal.privacy', 'legal.terms',
    ];

    /** Never blocked (PWA files, setup wizard, health check). */
    public const ALWAYS_ROUTES = ['manifest', 'setup.show', 'setup.store'];

    public const ALWAYS_PATHS = ['up', 'sw.js', 'manifest.webmanifest', 'robots.txt', 'favicon.ico'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.installed')
            || $request->routeIs(...self::ALWAYS_ROUTES)
            || $request->is(...self::ALWAYS_PATHS)
            || ! AppSettings::maintenanceMode()) {
            return $next($request);
        }

        $user = $request->user();
        if ($user?->isAdmin()) {
            return $next($request);
        }

        if ($user) {
            // Also right after a successful login of a non-admin.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } elseif ($request->routeIs(...self::GUEST_ROUTES)) {
            return $next($request);
        }

        return self::response($request);
    }

    public static function response(Request $request): Response
    {
        $message = AppSettings::maintenanceMessage();

        if ($request->expectsJson()) {
            return response()->json(['message' => trim(__('maintenance.page_title').' '.$message)], 503);
        }

        return response()
            ->view('maintenance', ['message' => $message], 503)
            ->header('Retry-After', '600');
    }
}

<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureBeerAccount;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only trust proxies listed in TRUSTED_PROXIES (comma separated, "*" for all); default none.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies);
        }
        // Once installed only the host of APP_URL is accepted (against Host header injection).
        // Before the setup any host is allowed, so the wizard works on any domain.
        $middleware->trustHosts(at: function () {
            $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

            return config('app.installed') && $host !== '' ? ['^'.preg_quote($host).'$'] : [];
        }, subdomains: false);
        $middleware->append(SecurityHeaders::class);
        // Maintenance mode for users (backend setting): after the session, so admins are recognized.
        $middleware->appendToGroup('web', MaintenanceMode::class);
        // Live refresh fingerprint, taken before the page content is built.
        $middleware->appendToGroup('web', \App\Http\Middleware\CaptureLiveFingerprint::class);
        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'beer' => EnsureBeerAccount::class,
            'role' => RequireRole::class,
        ]);
        // Guests hitting a protected page go to the login page "/".
        $middleware->redirectGuestsTo(fn () => route('login'));
        // Logged-in users hitting guest-only pages go to the dashboard.
        // ("Nur Verwaltung" accounts go to the backend.)
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->homeUrl() ?? route('account.dashboard'));
        // Not installed yet: redirect to the wizard before "auth" touches the database.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureInstalled::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Live refresh requests (public/js/live.js) get 401 instead of a redirect to the login page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson() || $request->hasHeader('X-Live-Refresh'),
        );
    })->create();

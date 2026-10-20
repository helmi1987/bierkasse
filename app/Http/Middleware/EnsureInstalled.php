<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects to the setup wizard until the installation is complete
 * and hides the wizard afterwards.
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $installed = (bool) config('app.installed');
        $isSetup = $request->routeIs('setup.*');

        if (! $installed && ! $isSetup) {
            return redirect()->route('setup.show');
        }

        if ($installed && $isSetup) {
            abort(404);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\LiveFingerprint;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the live-refresh fingerprint before the controller and the view run, so the fingerprint
 * rendered into the page is never newer than the content (a change in between is then seen as a
 * difference by the next poll instead of being hidden).
 */
class CaptureLiveFingerprint
{
    public const KEY = 'live.fingerprint';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->user()) {
            app()->instance(self::KEY, LiveFingerprint::current());
        }

        return $next($request);
    }
}

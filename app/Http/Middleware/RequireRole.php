<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * role:kassier (kassier or admin) / role:admin.
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        $allowed = match ($role) {
            'admin' => $user?->isAdmin(),
            'kassier' => $user?->isKassier(),
            default => false,
        };

        abort_unless($allowed, 403);

        return $next($request);
    }
}

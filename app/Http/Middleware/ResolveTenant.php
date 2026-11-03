<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the current tenant of the request (App\Services\Tenancy). Single mode: the only tenant.
 * Multi mode: the tenant of the logged-in user (superadmins: the one chosen in the session), for guests the
 * sub-domain, the link parameter ?mandant=<slug>, a code in the request or the tenant remembered in the session.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Tenancy::isMulti()) {
            Tenancy::clear();

            return $next($request);
        }

        $user = Auth::user();
        if ($user) {
            $tenant = null;
            if ($user->superadmin) {
                $id = $request->session()->get(Tenancy::SESSION_KEY);
                $tenant = $id ? Tenant::query()->find((int) $id) : null;
            }
            $tenant ??= Tenant::query()->find((int) $user->tenant_id);
            if ($tenant !== null) {
                $request->session()->put(Tenancy::SESSION_KEY, $tenant->id);
            }
            Tenancy::set($tenant);

            return $next($request);
        }

        $tenant = Tenancy::fromHost($request->getHost())
            ?? Tenancy::fromSlug($request->query(Tenancy::QUERY) ?? $request->input(Tenancy::QUERY))
            ?? Tenancy::fromCodes($request);
        if ($tenant === null && ($id = $request->session()->get(Tenancy::GUEST_KEY))) {
            $tenant = Tenant::query()->find((int) $id);
        }
        if ($tenant !== null) {
            $request->session()->put(Tenancy::GUEST_KEY, $tenant->id);
        }
        Tenancy::set($tenant);

        return $next($request);
    }
}

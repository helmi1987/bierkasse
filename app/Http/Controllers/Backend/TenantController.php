<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DatabaseBackup;
use App\Services\Tenancy;
use App\Support\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Admin > Mandanten (/admin/mandanten). Single mode: every admin sees the switch to multi mode.
 * Multi mode: superadmins manage the tenants (create, rename, delete, switch into one, back to single
 * mode while only one tenant is left); a tenant admin can rename the own tenant.
 */
class TenantController extends Controller
{
    public function index(Request $request): View
    {
        $me = $request->user();
        $multi = Tenancy::isMulti();
        $tenants = $multi && $me->isSuperadmin()
            ? Tenant::query()->withCount('users')->orderBy('name')->get()
            : Tenant::query()->whereKey(Tenancy::currentId())->withCount('users')->get();

        return view('backend.tenants', [
            'multi' => $multi,
            'superadmin' => $me->isSuperadmin(),
            'tenants' => $tenants,
            'current' => Tenancy::current(),
            'home' => $me->tenant,
            'superadmins' => $multi ? User::withoutTenant()->with('tenant')->where('superadmin', true)->orderBy('username')->get() : collect(),
            'backups' => DatabaseBackup::list(),
            'baseHost' => Tenancy::baseHost(),
        ]);
    }

    /** Switches the tenancy mode (single -> multi: any admin; multi -> single: superadmin, one tenant left). */
    public function mode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(Tenancy::MODES)]]);
        $me = $request->user();

        try {
            if ($data['mode'] === Tenancy::MODE_MULTI) {
                abort_if(Tenancy::isMulti(), 404);
                Tenancy::enableMulti($me);
                $message = __('tenants.multi_enabled');
            } else {
                abort_unless(Tenancy::isMulti(), 404);
                abort_unless($me->isSuperadmin(), 403);
                Tenancy::disableMulti($me);
                $message = __('tenants.single_enabled');
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('tenants.error_failed', ['error' => Str::limit($e->getMessage(), 200)]));
        }

        return redirect()->route('backend.tenants')->with('status', $message);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSuperadmin($request);
        $request->merge([
            'slug' => Str::lower(trim((string) $request->input('slug'))),
            'admin_username' => Str::lower(trim((string) $request->input('admin_username'))),
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.Tenant::NAME_MAX],
            'slug' => ['required', 'string', 'max:32', 'regex:'.Tenant::SLUG_PATTERN, Rule::unique('tenants', 'slug')],
            'admin_username' => ['required', 'string', 'regex:'.UserRules::USERNAME_PATTERN, UserRules::notReserved()],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ]);

        try {
            $tenant = Tenancy::create($data['name'], $data['slug'], [
                'username' => $data['admin_username'],
                'email' => $data['admin_email'],
                'password' => $data['admin_password'],
            ], $request->user());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput($request->except(['admin_password', 'admin_password_confirmation']))
                ->with('error', __('tenants.error_failed', ['error' => Str::limit($e->getMessage(), 200)]));
        }

        return redirect()->route('backend.tenants')->with('status', __('tenants.created', ['name' => $tenant->name]));
    }

    /** Rename (superadmin: any tenant incl. slug; tenant admin: the own, current tenant, name only). */
    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $me = $request->user();
        $superadmin = $me->isSuperadmin();
        abort_unless(Tenancy::isMulti() && ($superadmin || ($me->isAdmin() && $tenant->id === Tenancy::currentId())), 403);

        $request->merge(['slug' => Str::lower(trim((string) $request->input('slug', $tenant->slug)))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.Tenant::NAME_MAX],
            'slug' => ['required', 'string', 'max:32', 'regex:'.Tenant::SLUG_PATTERN, Rule::unique('tenants', 'slug')->ignore($tenant->id)],
        ]);

        Tenancy::rename($tenant, $data['name'], $superadmin ? $data['slug'] : null, $me);

        return back()->with('status', __('tenants.renamed'));
    }

    /** Deletes a tenant with all its data (superadmin; confirmed by typing the slug; backup first). */
    public function destroy(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->authorizeSuperadmin($request);
        $request->validate(['confirm' => ['required', 'string']]);
        if (trim((string) $request->input('confirm')) !== $tenant->slug) {
            return back()->with('error', __('tenants.error_confirm'));
        }

        try {
            Tenancy::delete($tenant, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('tenants.error_failed', ['error' => Str::limit($e->getMessage(), 200)]));
        }
        if ($request->session()->get(Tenancy::SESSION_KEY) === $tenant->id) {
            $request->session()->put(Tenancy::SESSION_KEY, $request->user()->tenant_id);
        }

        return redirect()->route('backend.tenants')->with('status', __('tenants.deleted', ['name' => $tenant->name]));
    }

    /** Superadmin works in another tenant (as admin) until the next switch or logout. */
    public function switch(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->authorizeSuperadmin($request);
        $request->session()->put(Tenancy::SESSION_KEY, $tenant->id);

        return redirect()->route('backend.dashboard')->with('status', __('tenants.switched', ['name' => $tenant->name]));
    }

    private function authorizeSuperadmin(Request $request): void
    {
        abort_unless(Tenancy::isMulti() && $request->user()?->isSuperadmin(), 403);
    }
}

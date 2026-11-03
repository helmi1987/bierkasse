<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Audit;
use App\Services\Sso;
use App\Services\Tenancy;
use App\Support\AppSettings;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Local login (password, plus TOTP when the user enabled it) and SSO login
 * (only for accounts that linked the provider in their settings).
 */
class LoginController extends Controller
{
    /** Pending second factor: [user, remember, method, expires]. */
    private const PENDING = 'login.pending';

    /** Pending tenant choice (multi mode, same user name and password in several tenants): [users, remember, expires]. */
    private const PENDING_TENANT = 'login.tenants';

    /** Seconds a login waits for the TOTP code. */
    private const PENDING_TTL = 300;

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->to(Auth::user()->homeUrl());
        }

        return view('auth.login', [
            'providers' => SsoProvider::usable(),
            'registration' => AppSettings::registrationOpen(),
            'personalSso' => AppSettings::personalSsoAllowed(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $username = Str::lower(trim($data['username']));

        // Per account+IP and per IP (against trying many user names).
        $key = 'login:'.$username.'|'.$request->ip();
        $ipKey = 'login-ip:'.$request->ip();
        foreach ([[$key, 5], [$ipKey, 20]] as [$k, $max]) {
            if (RateLimiter::tooManyAttempts($k, $max)) {
                return back()->onlyInput('username')->withErrors(['username' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($k)])]);
            }
        }

        $candidates = $this->findLoginUsers($username);
        // Every candidate is checked (multi mode: the same name may exist in several tenants); without one,
        // hash once anyway so response times do not reveal existing user names.
        $matched = $candidates->filter(fn (User $u) => (string) $u->password !== '' && Hash::check($data['password'], $u->password));
        if ($candidates->isEmpty()) {
            Hash::check($data['password'], '$2y$12$'.str_repeat('a', 53));
        }

        if ($matched->isEmpty()) {
            RateLimiter::hit($key, 300);
            RateLimiter::hit($ipKey, 900);
            // The user name tried, never the password.
            Audit::try('auth.login_failed', $candidates->count() === 1 ? $candidates->first() : null, [], ['username' => Str::limit($username, 100, '')]);

            return back()->onlyInput('username')->withErrors(['username' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        if ($matched->count() > 1) {
            // Same name and password in several tenants: the user picks the tenant.
            $request->session()->regenerate();
            $request->session()->put(self::PENDING_TENANT, [
                'users' => $matched->pluck('id')->all(),
                'remember' => $request->boolean('remember'),
                'expires' => time() + self::PENDING_TTL,
            ]);

            return redirect()->route('login.tenant');
        }

        return $this->continueLogin($request, $matched->first(), $request->boolean('remember'));
    }

    /** Tenant choice after the password (multi mode only, see login()). */
    public function tenantForm(Request $request): View|RedirectResponse
    {
        $users = $this->pendingTenantUsers($request);
        if ($users === null) {
            return redirect()->route('login');
        }

        return view('auth.tenant', ['users' => $users]);
    }

    public function tenantChoose(Request $request): RedirectResponse
    {
        $users = $this->pendingTenantUsers($request);
        if ($users === null) {
            return redirect()->route('login')->withErrors(['username' => __('auth.login_expired')]);
        }
        $request->validate(['user' => ['required', 'integer']]);
        $user = $users->firstWhere('id', (int) $request->input('user'));
        if ($user === null) {
            return back()->withErrors(['user' => __('auth.tenant.invalid')]);
        }
        $pending = (array) $request->session()->pull(self::PENDING_TENANT);

        return $this->continueLogin($request, $user, (bool) ($pending['remember'] ?? false));
    }

    /** After the password: locked check, then the second factor or the login itself. */
    private function continueLogin(Request $request, User $user, bool $remember): RedirectResponse
    {
        // Locked accounts: only after the correct password, so the lock does not reveal account names.
        if ($user->isLocked()) {
            return $this->lockedResponse($user);
        }

        if (! $user->hasTotp()) {
            return $this->finish($request, $user, $remember, 'local');
        }

        return $this->askTotp($request, $user, $remember, 'local');
    }

    /**
     * Users of a pending tenant choice (with their tenants), null when none or expired.
     *
     * @return \Illuminate\Support\Collection<int, User>|null
     */
    private function pendingTenantUsers(Request $request): ?\Illuminate\Support\Collection
    {
        $pending = $request->session()->get(self::PENDING_TENANT);
        if (! is_array($pending) || ($pending['expires'] ?? 0) < time() || ! Tenancy::isMulti()) {
            $request->session()->forget(self::PENDING_TENANT);

            return null;
        }
        $users = User::withoutTenant()->with('tenant')->whereIn('id', (array) ($pending['users'] ?? []))
            ->whereIn('status', [User::STATUS_ACTIVE, User::STATUS_LOCKED])->get()->sortBy(fn (User $u) => $u->tenant?->name)->values();

        return $users->count() > 1 ? $users : null;
    }


    public function totpForm(Request $request): View|RedirectResponse
    {
        if (! $this->pending($request)) {
            return redirect()->route('login');
        }

        return view('auth.totp');
    }

    public function totpVerify(Request $request): RedirectResponse
    {
        $pending = $this->pending($request);
        if (! $pending) {
            return redirect()->route('login')->withErrors(['username' => __('auth.login_expired')]);
        }

        $request->validate(['code' => ['required', 'string', 'max:32']]);

        $key = 'totp:'.$pending['user'];
        $ipKey = 'totp-ip:'.$request->ip();
        foreach ([[$key, 5], [$ipKey, 20]] as [$k, $max]) {
            if (RateLimiter::tooManyAttempts($k, $max)) {
                return back()->withErrors(['code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($k)])]);
            }
        }

        // The pending user may belong to a tenant that is not the request's tenant context yet (multi mode).
        $user = User::withoutTenant()->where('id', $pending['user'])->whereIn('status', [User::STATUS_ACTIVE, User::STATUS_LOCKED])->first();
        if ($user?->isLocked()) {
            // Locked while the second factor was pending.
            $request->session()->forget(self::PENDING);

            return $this->lockedResponse($user);
        }
        if (! $user || ! Totp::verifyUser($user, (string) $request->input('code'))) {
            RateLimiter::hit($key, 300);
            RateLimiter::hit($ipKey, 900);
            Audit::try('auth.totp_failed', $user);

            // After too many wrong codes the password has to be entered again.
            if (RateLimiter::tooManyAttempts($key, 5)) {
                $request->session()->forget(self::PENDING);

                return redirect()->route('login')->withErrors(['username' => __('auth.totp_too_many')]);
            }

            return back()->withErrors(['code' => __('auth.totp_invalid')]);
        }

        RateLimiter::clear($key);
        $request->session()->forget(self::PENDING);

        return $this->finish($request, $user, (bool) $pending['remember'], (string) $pending['method']);
    }

    public function totpCancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::PENDING);

        return redirect()->route('login');
    }

    public function logout(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            Audit::try('auth.logout', Auth::user());
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('auth.logged_out'));
    }

    public function ssoRedirect(Request $request, string $provider, Sso $sso): RedirectResponse
    {
        $p = $this->provider($provider);
        // Personal providers are started via /login/eigener (username) only.
        abort_if($p->isPersonal(), 404);

        try {
            return redirect()->away($sso->redirect($p, $request, Sso::MODE_LOGIN));
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->withErrors(['username' => __('sso.login_failed', ['provider' => $p->name, 'error' => Str::limit($e->getMessage(), 200)])]);
        }
    }

    /**
     * Callback for both login and linking (one redirect URI per provider).
     */
    public function ssoCallback(Request $request, string $provider, Sso $sso): RedirectResponse
    {
        $p = $this->provider($provider);
        $linking = Sso::pendingMode($request) === Sso::MODE_LINK;
        $failRoute = $linking && Auth::check() ? 'account.settings' : 'login';

        try {
            $result = $sso->callback($p, $request);

            if ($result['mode'] === Sso::MODE_LINK) {
                $user = $request->user();
                if (! $user || $user->id !== $result['user']) {
                    return redirect()->route('login')->withErrors(['username' => __('sso.error_state')]);
                }
                $sso->link($p, $user, $result['subject'], $result['label']);
                Audit::try('user.sso_link', $user, [], ['sso_provider' => $p->slug]);

                return redirect()->route('account.settings')->with('status', __('sso.linked', ['provider' => $p->name]));
            }

            $user = $sso->findUser($p, $result['subject']);
        } catch (Throwable $e) {
            report($e);
            $field = $failRoute === 'login' ? 'username' : 'sso';

            return redirect()->route($failRoute)->withErrors([$field => __('sso.login_failed', ['provider' => $p->name, 'error' => Str::limit($e->getMessage(), 200)])]);
        }

        if ($user?->isLocked()) {
            return $this->lockedResponse($user);
        }
        if (! $user || ! $user->isActive()) {
            return redirect()->route('login')->withErrors(['username' => __('sso.no_user', ['provider' => $p->name])]);
        }

        // Second factor also after SSO when the user enabled TOTP.
        if ($user->hasTotp()) {
            return $this->askTotp($request, $user, false, 'sso');
        }

        return $this->finish($request, $user, false, 'sso');
    }

    private function provider(string $slug): SsoProvider
    {
        $p = SsoProvider::query()->where('slug', $slug)->first();
        abort_unless($p && $p->isUsable(), 404);

        return $p;
    }

    private function askTotp(Request $request, User $user, bool $remember, string $method): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->put(self::PENDING, [
            'user' => $user->id,
            'remember' => $remember,
            'method' => $method,
            'expires' => time() + self::PENDING_TTL,
        ]);

        return redirect()->route('login.totp');
    }

    /**
     * @return array{user:int,remember:bool,method:string,expires:int}|null
     */
    private function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING);
        if (! is_array($pending) || ($pending['expires'] ?? 0) < time()) {
            $request->session()->forget(self::PENDING);

            return null;
        }

        return $pending;
    }

    private function finish(Request $request, User $user, bool $remember, string $method): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('auth.method', $method);
        // The user's tenant is the tenant of the session from now on (superadmins may switch later).
        $request->session()->forget(Tenancy::GUEST_KEY);
        $request->session()->put(Tenancy::SESSION_KEY, $user->tenant_id);
        if (Tenancy::isMulti()) {
            Tenancy::set($user->tenant);
        }
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::try('auth.login', $user, [], ['method' => $method], $user);

        if ($user->must_change_password) {
            return redirect()->route('account.settings')->with('warning', __('auth.must_change_password'));
        }

        // "Nur Verwaltung": always to the backend (an intended /konto URL would only redirect there).
        if ($user->isAdminOnly()) {
            $request->session()->forget('url.intended');

            return redirect()->to($user->homeUrl());
        }

        return redirect()->intended($user->homeUrl());
    }

    /** Login refused for a locked account (message with the public contact e-mail). */
    private function lockedResponse(User $user): RedirectResponse
    {
        Audit::try('auth.login_locked', $user, [], ['username' => $user->username], $user);

        return redirect()->route('login')->withErrors(['username' => __('auth.locked', ['email' => AppSettings::legalEmail()])]);
    }

    /**
     * Active (or locked, refused after the password check) users by user name, or by e-mail address when the
     * input contains "@" (only if unambiguous within a tenant). Within the current tenant; without tenant
     * context (multi mode, base host) across all tenants: the password then decides, see login().
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function findLoginUsers(string $login): \Illuminate\Support\Collection
    {
        $query = (Tenancy::current() === null ? User::withoutTenant() : User::query())
            ->whereIn('status', [User::STATUS_ACTIVE, User::STATUS_LOCKED]);
        if (! str_contains($login, '@')) {
            return $query->where('username', $login)->get();
        }

        // Several accounts with the same e-mail in one tenant: none of them (ambiguous).
        return $query->whereRaw('LOWER(email) = ?', [$login])->get()
            ->groupBy('tenant_id')->filter(fn ($group) => $group->count() === 1)->flatten(1)->values();
    }
}

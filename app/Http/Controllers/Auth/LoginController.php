<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Sso;
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

    /** Seconds a login waits for the TOTP code. */
    private const PENDING_TTL = 300;

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('account.dashboard');
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

        $user = $this->findLoginUser($username);
        // Always hash once so response times do not reveal existing user names.
        $ok = Hash::check($data['password'], $user?->password ?: '$2y$12$'.str_repeat('a', 53)) && $user?->password;

        if (! $ok) {
            RateLimiter::hit($key, 300);
            RateLimiter::hit($ipKey, 900);

            return back()->onlyInput('username')->withErrors(['username' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        if (! $user->hasTotp()) {
            return $this->finish($request, $user, $request->boolean('remember'), 'local');
        }

        return $this->askTotp($request, $user, $request->boolean('remember'), 'local');
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

        $user = User::query()->where('id', $pending['user'])->where('status', User::STATUS_ACTIVE)->first();
        if (! $user || ! Totp::verifyUser($user, (string) $request->input('code'))) {
            RateLimiter::hit($key, 300);
            RateLimiter::hit($ipKey, 900);

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

                return redirect()->route('account.settings')->with('status', __('sso.linked', ['provider' => $p->name]));
            }

            $user = $sso->findUser($p, $result['subject']);
        } catch (Throwable $e) {
            report($e);
            $field = $failRoute === 'login' ? 'username' : 'sso';

            return redirect()->route($failRoute)->withErrors([$field => __('sso.login_failed', ['provider' => $p->name, 'error' => Str::limit($e->getMessage(), 200)])]);
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
        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->must_change_password) {
            return redirect()->route('account.settings')->with('warning', __('auth.must_change_password'));
        }

        return redirect()->intended(route('account.dashboard'));
    }

    /** Active user by user name, or by e-mail address when the input contains "@" (only if unambiguous). */
    private function findLoginUser(string $login): ?User
    {
        $query = User::query()->where('status', User::STATUS_ACTIVE);
        if (! str_contains($login, '@')) {
            return $query->where('username', $login)->first();
        }

        $matches = $query->whereRaw('LOWER(email) = ?', [$login])->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}

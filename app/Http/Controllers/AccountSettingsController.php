<?php

namespace App\Http\Controllers;

use App\Models\SsoProvider;
use App\Models\UserIdentity;
use App\Services\Audit;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Services\Sso;
use App\Support\AppSettings;
use App\Support\Qr;
use App\Support\Totp;
use App\Support\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Own account settings: name, e-mail, password, TOTP, linked SSO identities, statistics opt-out.
 */
class AccountSettingsController extends Controller
{
    private const SETUP = 'totp.setup_secret';

    public function show(Request $request): View
    {
        $user = $request->user();
        $secret = null;

        if (! $user->hasTotp()) {
            $secret = $request->session()->get(self::SETUP);
            if (! $secret) {
                $secret = Totp::generateSecret();
                $request->session()->put(self::SETUP, $secret);
            }
        }

        $uri = $secret ? Totp::uri($secret, $user->username, AppSettings::appName()) : null;
        $identities = $user->identities()->with('provider')->get();
        $personal = $user->personalSsoProvider()->first();
        $personalIdentity = $personal ? $identities->firstWhere('sso_provider_id', $personal->id) : null;
        $usable = SsoProvider::usable();

        return view('account.settings', [
            'user' => $user,
            'adminOnlySettled' => $user->isKassier() && Ledger::adminOnlySettled($user),
            'secret' => $secret,
            'qr' => $uri ? Qr::dataUri($uri, 220) : null,
            'recoveryLeft' => $user->hasTotp() ? Totp::remainingRecoveryCodes($user) : 0,
            'newCodes' => $request->session()->get('totp.recovery_codes'),
            'identities' => $identities->reject(fn ($i) => $i->provider?->isPersonal())->values(),
            'personalAllowed' => AppSettings::personalSsoAllowed(),
            'personal' => $personal,
            'personalIdentity' => $personalIdentity,
            'personalRedirectUri' => PersonalSsoController::redirectUriFor($request, $personal),
            'personalLoginUrl' => route('login.personal.user', $user->username),
            'ssoActive' => $usable->isNotEmpty(),
            'providers' => $usable->reject(fn ($p) => $identities->contains('sso_provider_id', $p->id))->values(),
        ]);
    }

    public function profile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(UserRules::profile($user->id, $user->isKassier()));
        Audit::save('user.profile', $user->fill($data));

        return back()->with('status', __('account.profile_saved'));
    }

    public function stats(Request $request): RedirectResponse
    {
        $user = $request->user();
        Audit::save('user.settings', $user->forceFill(['stats_opt_out' => ! $request->boolean('show')]));

        return back()->with('status', __('account.stats_saved'));
    }

    /** "Nur Verwaltung" on/off for the own account (kassiers and admins only). */
    public function adminOnly(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isKassier(), 403);
        $on = $request->boolean('admin_only');

        try {
            Ledger::setAdminOnly($user, $on);
        } catch (LedgerException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('account.settings')->with('status', __($on ? 'account.admin_only_on' : 'account.admin_only_off'));
    }

    public function password(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => [$user->password ? 'required' : 'nullable', 'string'],
            'password' => UserRules::password(),
        ]);

        if ($user->password && ! Hash::check((string) $request->input('current_password'), $user->password)) {
            return back()->withErrors(['current_password' => __('account.password_current_wrong')]);
        }

        Audit::save('user.password_change', $user->forceFill(['password' => $request->input('password'), 'must_change_password' => false]), true);
        // End all other sessions of this account (AuthenticateSession is on the route group).
        Auth::logoutOtherDevices((string) $request->input('password'));

        return redirect()->route('account.settings')->with('status', __('account.password_changed'));
    }

    public function enableTotp(Request $request): RedirectResponse
    {
        $user = $request->user();
        $secret = (string) $request->session()->get(self::SETUP, '');

        if ($user->hasTotp() || $secret === '') {
            return redirect()->route('account.settings');
        }
        if ($limited = $this->limited($request)) {
            return $limited;
        }

        $request->validate(['code' => ['required', 'string', 'max:16']]);
        $step = Totp::verify($secret, (string) $request->input('code'));
        if ($step === null) {
            RateLimiter::hit($this->key($request), 300);

            return back()->withErrors(['code' => __('account.totp_code_invalid_setup')]);
        }

        DB::transaction(function () use ($user, $secret, $step) {
            $user->forceFill([
                'totp_secret' => $secret,
                'totp_confirmed_at' => now(),
                'totp_last_step' => $step,
            ])->save();
            Audit::log('user.totp_enable', $user);
        });
        $request->session()->forget(self::SETUP);

        return redirect()->route('account.settings')
            ->with('status', __('account.totp_enabled'))
            ->with('totp.recovery_codes', Totp::newRecoveryCodes($user));
    }

    public function restartTotp(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SETUP);

        return redirect()->route('account.settings');
    }

    public function disableTotp(Request $request): RedirectResponse
    {
        if ($error = $this->checkCode($request)) {
            return $error;
        }

        DB::transaction(function () use ($request) {
            $request->user()->disableTotp();
            Audit::log('user.totp_disable', $request->user());
        });

        return redirect()->route('account.settings')->with('status', __('account.totp_disabled'));
    }

    public function regenerateRecovery(Request $request): RedirectResponse
    {
        if ($error = $this->checkCode($request)) {
            return $error;
        }

        Audit::log('user.totp_recovery', $request->user());

        return redirect()->route('account.settings')
            ->with('status', __('account.recovery_regenerated'))
            ->with('totp.recovery_codes', Totp::newRecoveryCodes($request->user()));
    }

    public function linkSso(Request $request, string $provider, Sso $sso): RedirectResponse
    {
        $p = SsoProvider::query()->where('slug', $provider)->first();
        abort_unless($p && $p->isUsable() && ! $p->isPersonal(), 404);

        try {
            return redirect()->away($sso->redirect($p, $request, Sso::MODE_LINK));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['sso' => __('sso.login_failed', ['provider' => $p->name, 'error' => Str::limit($e->getMessage(), 200)])]);
        }
    }

    public function unlinkSso(Request $request, UserIdentity $identity): RedirectResponse
    {
        abort_unless($identity->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($identity) {
            $identity->delete();
            Audit::log('user.sso_unlink', $identity, ['sso_provider_id' => $identity->sso_provider_id]);
        });

        return back()->with('status', __('sso.unlinked'));
    }

    private function checkCode(Request $request): ?RedirectResponse
    {
        if (! $request->user()->hasTotp()) {
            return redirect()->route('account.settings');
        }
        if ($limited = $this->limited($request)) {
            return $limited;
        }

        $request->validate(['code' => ['required', 'string', 'max:32']]);
        if (! Totp::verifyUser($request->user(), (string) $request->input('code'))) {
            RateLimiter::hit($this->key($request), 300);

            return back()->withErrors(['code' => __('auth.totp_invalid')]);
        }

        return null;
    }

    private function limited(Request $request): ?RedirectResponse
    {
        return RateLimiter::tooManyAttempts($this->key($request), 5)
            ? back()->withErrors(['code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($this->key($request))])])
            : null;
    }

    private function key(Request $request): string
    {
        return 'totp-account:'.$request->user()->id;
    }
}

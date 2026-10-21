<?php

namespace App\Http\Controllers;

use App\Models\SsoProvider;
use App\Models\UserIdentity;
use App\Services\Sso;
use App\Support\AppSettings;
use App\Support\Qr;
use App\Support\Totp;
use App\Support\UserRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $data = $request->validate(UserRules::profile($user->id));
        $user->fill($data)->save();

        return back()->with('status', __('account.profile_saved'));
    }

    public function stats(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['stats_opt_out' => ! $request->boolean('show')])->save();

        return back()->with('status', __('account.stats_saved'));
    }

    /** Mail when a colleague pays the user a beer ("Runde spendieren"). */
    public function roundMail(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['round_mail' => $request->boolean('round_mail')])->save();

        return back()->with('status', __('round.mail_setting_saved'));
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

        $user->forceFill(['password' => $request->input('password'), 'must_change_password' => false])->save();
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

        $user->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
            'totp_last_step' => $step,
        ])->save();
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

        $request->user()->disableTotp();

        return redirect()->route('account.settings')->with('status', __('account.totp_disabled'));
    }

    public function regenerateRecovery(Request $request): RedirectResponse
    {
        if ($error = $this->checkCode($request)) {
            return $error;
        }

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
        $identity->delete();

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

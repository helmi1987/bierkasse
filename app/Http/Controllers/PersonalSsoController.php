<?php

namespace App\Http\Controllers;

use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Sso;
use App\Support\AppSettings;
use App\Support\OutboundUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Personal OAuth2 / OIDC provider of a user (e.g. self-hosted Authentik or Keycloak):
 * manage it in the account settings, link it and log in with it via the user name.
 * The callback is the shared SSO callback (LoginController::ssoCallback).
 */
class PersonalSsoController extends Controller
{
    /** Slug reserved for a provider not saved yet (shown as redirect URI before saving). */
    private const PENDING_SLUG = 'personal_sso.slug';

    /** Login attempts per IP per minute and per user name per 10 minutes. */
    private const MAX_PER_IP = 10;

    private const MAX_PER_USER = 10;

    /**
     * Redirect URI the user registers at the provider (also before the first save).
     */
    public static function redirectUriFor(Request $request, ?SsoProvider $provider): string
    {
        return $provider ? $provider->redirectUri() : route('login.sso.callback', self::pendingSlug($request));
    }

    public function save(Request $request): RedirectResponse
    {
        abort_unless(AppSettings::personalSsoAllowed(), 404);
        $user = $request->user();
        $provider = $user->personalSsoProvider()->first();
        $isNew = $provider === null;

        $url = ['nullable', 'url', 'max:500'];
        $claim = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:\/-]+$/'];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'discovery_url' => $url,
            'issuer' => ['nullable', 'string', 'max:500'],
            'authorize_url' => $url,
            'token_url' => $url,
            'userinfo_url' => $url,
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => [$isNew ? 'required' : 'nullable', 'string', 'max:2000'],
            'scopes' => ['nullable', 'string', 'max:255'],
            'token_auth' => ['nullable', Rule::in(['post', 'basic'])],
            'subject_claim' => $claim,
            'label_claim' => $claim,
        ]);

        if ($isNew) {
            $provider = new SsoProvider;
            $provider->forceFill([
                'owner_user_id' => $user->id,
                'slug' => self::pendingSlug($request),
                'type' => 'oidc',
                'enabled' => true,
                'sort' => 0,
                'email_claim' => 'email',
            ]);
        }

        $secret = (string) ($data['client_secret'] ?? '');
        $provider->fill([
            'name' => $data['name'],
            'discovery_url' => ($data['discovery_url'] ?? '') !== '' ? Sso::normalizeDiscoveryUrl($data['discovery_url']) : null,
            'issuer' => $data['issuer'] ?? null,
            'authorize_url' => $data['authorize_url'] ?? null,
            'token_url' => $data['token_url'] ?? null,
            'userinfo_url' => $data['userinfo_url'] ?? null,
            'client_id' => $data['client_id'],
            'scopes' => trim((string) ($data['scopes'] ?? '')) ?: 'openid profile email',
            'token_auth' => $data['token_auth'] ?? 'post',
            'use_pkce' => $request->boolean('use_pkce', true),
            'subject_claim' => ($data['subject_claim'] ?? '') ?: 'sub',
            'label_claim' => ($data['label_claim'] ?? '') ?: 'preferred_username',
        ]);
        if ($secret !== '') {
            $provider->client_secret = $secret;
        }

        try {
            if ((string) $provider->discovery_url !== '' && ($provider->isDirty('discovery_url') || $request->boolean('rediscover') || (string) $provider->authorize_url === '')) {
                $discovered = array_filter(Sso::discover((string) $provider->discovery_url, true), fn ($v) => is_string($v) && $v !== '');
                $provider->fill($discovered + ['discovered_at' => now()]);
            }
            // Every endpoint is checked again (manual values, discovery results).
            foreach (['authorize_url', 'token_url', 'userinfo_url'] as $field) {
                if ((string) $provider->{$field} !== '') {
                    OutboundUrl::assertPublic((string) $provider->{$field});
                }
            }
        } catch (Throwable $e) {
            return back()->withInput($request->except('client_secret'))
                ->withErrors(['personal_sso' => __('sso.personal.save_failed', ['error' => Str::limit($e->getMessage(), 200)])]);
        }

        // Another issuer/client means another account namespace: the link has to be made again.
        $relink = ! $isNew && $provider->isDirty(['discovery_url', 'issuer', 'authorize_url', 'token_url', 'userinfo_url', 'client_id', 'subject_claim']);
        $provider->save();
        if ($relink) {
            $provider->identities()->delete();
        }
        $request->session()->forget(self::PENDING_SLUG);

        $redirect = redirect()->to(route('account.settings').'#eigener-anbieter');

        return $provider->isUsable()
            ? $redirect->with('status', __('sso.personal.saved'))
            : $redirect->with('warning', __('sso.personal.saved_incomplete'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $provider = $request->user()->personalSsoProvider()->first();
        if ($provider) {
            $provider->identities()->delete();
            $provider->delete();
        }

        return redirect()->to(route('account.settings').'#eigener-anbieter')->with('status', __('sso.personal.deleted'));
    }

    /**
     * Starts the OAuth flow that links the subject to the own account.
     */
    public function connect(Request $request, Sso $sso): RedirectResponse
    {
        abort_unless(AppSettings::personalSsoAllowed(), 404);
        $provider = $request->user()->personalSsoProvider()->first();
        $back = redirect()->to(route('account.settings').'#eigener-anbieter');

        if (! $provider || ! $provider->isUsable()) {
            return $back->withErrors(['personal_sso' => __('sso.personal.incomplete')]);
        }

        try {
            return redirect()->away($sso->redirect($provider, $request, Sso::MODE_LINK));
        } catch (Throwable $e) {
            report($e);

            return $back->withErrors(['personal_sso' => __('sso.login_failed', ['provider' => $provider->name, 'error' => Str::limit($e->getMessage(), 200)])]);
        }
    }

    public function loginForm(): View|RedirectResponse
    {
        abort_unless(AppSettings::personalSsoAllowed(), 404);
        if (Auth::check()) {
            return redirect()->route('account.dashboard');
        }

        return view('auth.personal-sso');
    }

    public function loginPost(Request $request, Sso $sso): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:255']]);

        return $this->start($request, $sso, $data['username']);
    }

    /**
     * Bookmarkable login link /login/eigener/{username}.
     */
    public function loginUser(Request $request, Sso $sso, string $username): RedirectResponse
    {
        return $this->start($request, $sso, $username);
    }

    private function start(Request $request, Sso $sso, string $username): RedirectResponse
    {
        abort_unless(AppSettings::personalSsoAllowed(), 404);
        if (Auth::check()) {
            return redirect()->route('account.dashboard');
        }

        $username = Str::lower(trim($username));
        $fail = fn (string $message) => redirect()->route('login.personal')
            ->withInput(['username' => $username])->withErrors(['username' => $message]);

        // Every attempt counts (also successful ones): the answer tells whether a provider exists.
        $ipKey = 'personal-sso-ip:'.$request->ip();
        $userKey = 'personal-sso-user:'.sha1($username);
        foreach ([[$ipKey, self::MAX_PER_IP], [$userKey, self::MAX_PER_USER]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return $fail(__('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]));
            }
        }
        RateLimiter::hit($ipKey, 60);
        RateLimiter::hit($userKey, 600);

        $user = User::query()->where('username', $username)->where('status', User::STATUS_ACTIVE)->first();
        $provider = $user?->personalSsoProvider()->first();

        // Only offered when the provider is complete and already linked to its owner.
        if (! $user || ! $provider || ! $provider->isUsable() || ! $provider->identities()->where('user_id', $user->id)->exists()) {
            return $fail(__('sso.personal.login_unavailable'));
        }

        try {
            return redirect()->away($sso->redirect($provider, $request, Sso::MODE_LOGIN));
        } catch (Throwable $e) {
            report($e);

            return $fail(__('sso.personal.login_unavailable'));
        }
    }

    private static function pendingSlug(Request $request): string
    {
        $slug = (string) $request->session()->get(self::PENDING_SLUG, '');
        if ($slug === '' || SsoProvider::query()->where('slug', $slug)->exists()) {
            do {
                $slug = 'p-'.Str::lower(Str::random(12));
            } while (SsoProvider::query()->where('slug', $slug)->exists());
            $request->session()->put(self::PENDING_SLUG, $slug);
        }

        return $slug;
    }
}

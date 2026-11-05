<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\SsoProvider;
use App\Services\AppleClientSecret;
use App\Services\Audit;
use App\Services\Sso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * SSO providers (admin): generic OIDC, Google, Microsoft (tenant), GitHub, Facebook, Apple.
 */
class SsoProviderController extends Controller
{
    public function index(): View
    {
        return view('backend.sso.index', [
            'providers' => SsoProvider::query()->globalOnly()->withCount('identities')->orderBy('sort')->orderBy('name')->orderBy('id')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $type = in_array($request->query('type'), SsoProvider::TYPES, true) ? (string) $request->query('type') : 'oidc';

        return $this->form(new SsoProvider([
            'type' => $type,
            'name' => match ($type) {
                'google' => 'Google', 'microsoft' => 'Microsoft', 'github' => 'GitHub', 'facebook' => 'Facebook', 'apple' => 'Apple', default => '',
            },
            'tenant' => $type === 'microsoft' ? 'common' : null,
            'enabled' => true,
            'scopes' => $type === 'apple' ? 'name email' : 'openid profile email',
            'token_auth' => 'post',
            'use_pkce' => $type !== 'apple',
            'subject_claim' => 'sub',
            'email_claim' => 'email',
            'label_claim' => $type === 'apple' ? 'email' : 'preferred_username',
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $provider = new SsoProvider;
        $error = $this->save($request, $provider);

        if (! $provider->exists) {
            return back()->withInput($request->except('client_secret'))->with('error', $error);
        }

        return redirect()->route('backend.sso.edit', $provider)
            ->with($error ? 'error' : 'status', $error ?? __('sso.created'));
    }

    public function edit(SsoProvider $ssoProvider): View
    {
        $this->guardGlobal($ssoProvider);

        return $this->form($ssoProvider);
    }

    public function update(Request $request, SsoProvider $ssoProvider): RedirectResponse
    {
        $this->guardGlobal($ssoProvider);
        $error = $this->save($request, $ssoProvider);

        return back()->with($error ? 'error' : 'status', $error ?? __('settings.saved'));
    }

    public function destroy(SsoProvider $ssoProvider): RedirectResponse
    {
        $this->guardGlobal($ssoProvider);
        DB::transaction(function () use ($ssoProvider) {
            $ssoProvider->delete();
            Audit::log('sso_provider.delete', $ssoProvider, ['name' => $ssoProvider->name, 'slug' => $ssoProvider->slug]);
        });

        return redirect()->route('backend.sso.index')->with('status', __('sso.deleted'));
    }

    /**
     * Moves a provider one place up or down (login button order) and renumbers all global providers 1..n.
     */
    public function move(Request $request, SsoProvider $ssoProvider): RedirectResponse
    {
        $this->guardGlobal($ssoProvider);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        $providers = SsoProvider::query()->globalOnly()->orderBy('sort')->orderBy('name')->orderBy('id')->get()->values();
        $index = $providers->search(fn (SsoProvider $p) => $p->is($ssoProvider));
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index !== false && $target >= 0 && $target < $providers->count()) {
            $old = $providers->pluck('slug')->all();
            $ordered = $providers->all();
            [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

            DB::transaction(function () use ($ordered, $old, $ssoProvider) {
                foreach ($ordered as $i => $provider) {
                    if ((int) $provider->sort !== $i + 1) {
                        $provider->forceFill(['sort' => $i + 1])->save();
                    }
                }
                Audit::log('sso_provider.reorder', $ssoProvider, ['order' => $old], ['order' => array_map(fn (SsoProvider $p) => $p->slug, $ordered)]);
            });
        }

        return redirect()->route('backend.sso.index')->with('status', __('sso.reordered'));
    }

    /**
     * Personal providers belong to their owner and are not managed here.
     */
    private function guardGlobal(SsoProvider $provider): void
    {
        abort_if($provider->isPersonal(), 404);
    }

    private function form(SsoProvider $provider): View
    {
        return view('backend.sso.form', [
            'provider' => $provider,
            'redirectUri' => $provider->exists ? $provider->redirectUri() : url('/login/sso').'/<kuerzel>/callback',
            'redirectUriTemplate' => route('login.sso.callback', '__slug__'),
        ]);
    }

    /**
     * Saves the provider; presets fill the endpoints, a discovery URL refreshes them.
     * Returns an error message or null.
     */
    private function save(Request $request, SsoProvider $provider): ?string
    {
        $url = ['nullable', 'url', 'max:500'];
        // Apple: scopes, claims and token auth come from the preset (not shown in the form).
        $isApple = $request->input('type') === 'apple';
        $required = $isApple ? 'nullable' : 'required';
        $claim = [$required, 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:\/-]+$/'];
        $appleId = ['nullable', 'string', 'regex:/^[A-Z0-9]{10}$/'];

        $data = $request->validate([
            'type' => ['required', Rule::in(SsoProvider::TYPES)],
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/', Rule::unique('sso_providers', 'slug')->ignore($provider->id)],
            'tenant' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9.-]+$/'],
            'discovery_url' => $url,
            'issuer' => ['nullable', 'string', 'max:500'],
            'authorize_url' => $url,
            'token_url' => $url,
            'userinfo_url' => $url,
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:2000'],
            'apple_team_id' => $appleId,
            'apple_key_id' => $appleId,
            'scopes' => [$required, 'string', 'max:255'],
            'token_auth' => [$required, Rule::in(['post', 'basic'])],
            'subject_claim' => $claim,
            'email_claim' => $claim,
            'label_claim' => $claim,
            'sort' => ['nullable', 'integer'],
        ]);

        $data['slug'] = ($data['slug'] ?? '') ?: Str::slug($data['name']);
        if ($data['slug'] === '' || SsoProvider::query()->where('slug', $data['slug'])->whereKeyNot($provider->id)->exists()) {
            $data['slug'] = 'sso-'.Str::lower(Str::random(6));
        }
        if ($data['type'] === 'microsoft') {
            $data['tenant'] = trim((string) ($data['tenant'] ?? '')) ?: 'common';
        }

        foreach (['enabled', 'use_pkce'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        if ($data['type'] !== 'apple') {
            $data['apple_team_id'] = null;
            $data['apple_key_id'] = null;
        }

        // Presets override the endpoint fields of the fixed provider types (Apple also PKCE and token auth).
        $data = array_merge($data, Sso::preset($data['type'], $data['tenant'] ?? null));

        if ($data['discovery_url'] ?? null) {
            $data['discovery_url'] = Sso::normalizeDiscoveryUrl($data['discovery_url']);
        }

        foreach (['authorize_url', 'token_url', 'userinfo_url'] as $field) {
            if (($data[$field] ?? '') !== '') {
                try {
                    Sso::assertSafeUrl($data[$field]);
                } catch (Throwable $e) {
                    return $e->getMessage();
                }
            }
        }

        $secret = $data['client_secret'] ?? '';
        unset($data['client_secret']);

        // Apple: the "secret" is the private key (.p8); it must be an EC key on P-256 (ES256).
        if ($data['type'] === 'apple' && $secret !== '') {
            $secret = AppleClientSecret::normalizePem($secret);
            if (AppleClientSecret::loadKey($secret) === null) {
                return __('sso.apple_key_invalid');
            }
        }

        $data['sort'] = (int) ($data['sort'] ?? 0);

        $provider->fill($data);
        if ($secret !== '') {
            $provider->client_secret = $secret;
        }

        $error = null;
        if ((string) $provider->discovery_url !== '' && ($provider->isDirty('discovery_url') || $request->boolean('rediscover') || (string) $provider->authorize_url === '')) {
            try {
                // Never overwrite configured values with empty discovery results.
                $discovered = array_filter(Sso::discover($provider->discovery_url), fn ($v) => is_string($v) && $v !== '');
                $provider->fill($discovered + ['discovered_at' => now()]);
            } catch (Throwable $e) {
                $error = __('sso.saved_discovery_failed', ['error' => $e->getMessage()]);
            }
        }

        $created = ! $provider->exists;
        [$old, $new] = Audit::dirty($provider);
        DB::transaction(function () use ($provider, $created, $old, $new) {
            $provider->save();
            if ($created || $new !== []) {
                Audit::log($created ? 'sso_provider.create' : 'sso_provider.update', $provider, $old, $new);
            }
        });

        if (! $error && $provider->enabled && ! $provider->isUsable()) {
            $error = __('sso.saved_incomplete');
        }

        return $error;
    }
}

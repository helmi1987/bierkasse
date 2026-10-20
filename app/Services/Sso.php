<?php

namespace App\Services;

use App\Models\SsoProvider;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\AppSettings;
use App\Support\OutboundUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generic OAuth2 / OpenID Connect authorization code flow (with PKCE,
 * state and nonce). Used for two things:
 *  - login: only for users who linked the provider before;
 *  - link: a logged-in user links the provider to the own account.
 *
 * The ID token is received directly from the token endpoint over TLS
 * (https enforced by assertSafeUrl), so per OIDC Core 3.1.3.7 its
 * issuer/audience/expiry/nonce are validated without a signature check.
 *
 * Personal providers (owned by a user) are untrusted input: every server-side
 * request to them goes through OutboundUrl in strict mode (SSRF protection),
 * and their identities can only log in / link their owner.
 */
class Sso
{
    private const SESSION = 'sso.flow';

    /** Maximum age of a started flow in seconds. */
    private const FLOW_TTL = 600;

    public const MODE_LOGIN = 'login';

    public const MODE_LINK = 'link';

    /**
     * Endpoint presets per type. Microsoft and Google use their discovery documents.
     *
     * @return array<string, mixed>
     */
    public static function preset(string $type, ?string $tenant = null): array
    {
        $tenant = trim((string) $tenant) !== '' ? trim((string) $tenant) : 'common';

        return match ($type) {
            'google' => [
                'discovery_url' => 'https://accounts.google.com/.well-known/openid-configuration',
                'scopes' => 'openid profile email',
                'subject_claim' => 'sub',
                'email_claim' => 'email',
                'label_claim' => 'email',
            ],
            'microsoft' => [
                'discovery_url' => 'https://login.microsoftonline.com/'.rawurlencode($tenant).'/v2.0/.well-known/openid-configuration',
                'scopes' => 'openid profile email',
                'subject_claim' => 'sub',
                'email_claim' => 'email',
                'label_claim' => 'preferred_username',
            ],
            'github' => [
                'discovery_url' => null,
                'issuer' => null,
                'authorize_url' => 'https://github.com/login/oauth/authorize',
                'token_url' => 'https://github.com/login/oauth/access_token',
                'userinfo_url' => 'https://api.github.com/user',
                'scopes' => 'read:user user:email',
                'subject_claim' => 'id',
                'email_claim' => 'email',
                'label_claim' => 'login',
            ],
            // Facebook Login: plain OAuth2, user data from the Graph API (subject = app-scoped id).
            'facebook' => [
                'discovery_url' => null,
                'issuer' => null,
                'authorize_url' => 'https://www.facebook.com/v19.0/dialog/oauth',
                'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
                'userinfo_url' => 'https://graph.facebook.com/v19.0/me?fields=id,name,email',
                'scopes' => 'email public_profile',
                'subject_claim' => 'id',
                'email_claim' => 'email',
                'label_claim' => 'name',
            ],
            default => [],
        };
    }

    /**
     * Accepts an issuer URL or the full discovery URL.
     */
    public static function normalizeDiscoveryUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_contains($url, '/.well-known/')) {
            return $url;
        }

        return rtrim($url, '/').'/.well-known/openid-configuration';
    }

    /**
     * Fetches the discovery document and returns the endpoint fields.
     * Strict (personal providers): SSRF checks on the URL and on all returned endpoints.
     *
     * @return array{issuer:?string,authorize_url:string,token_url:string,userinfo_url:?string}
     */
    public static function discover(string $url, bool $strict = false): array
    {
        $url = self::normalizeDiscoveryUrl($url);
        self::assertSafeUrl($url);
        if ($strict) {
            $url = OutboundUrl::normalizeUrl($url);
        }

        $response = OutboundUrl::client($url, $strict, 10, true)->get($url);
        OutboundUrl::assertSize($response);
        if (! $response->successful()) {
            throw new RuntimeException(__('sso.error_discovery_http', ['status' => $response->status()]));
        }

        $doc = (array) $response->json();
        foreach (['authorization_endpoint', 'token_endpoint'] as $key) {
            if (! is_string($doc[$key] ?? null) || $doc[$key] === '') {
                throw new RuntimeException(__('sso.error_discovery_key', ['key' => $key]));
            }
        }

        $fields = [
            'issuer' => is_string($doc['issuer'] ?? null) ? $doc['issuer'] : null,
            'authorize_url' => $doc['authorization_endpoint'],
            'token_url' => $doc['token_endpoint'],
            'userinfo_url' => is_string($doc['userinfo_endpoint'] ?? null) ? $doc['userinfo_endpoint'] : null,
        ];

        foreach (['authorize_url', 'token_url', 'userinfo_url'] as $key) {
            if ($fields[$key] !== null) {
                if ($strict) {
                    $fields[$key] = OutboundUrl::normalizeUrl($fields[$key]);
                    OutboundUrl::assertPublic($fields[$key]);
                } else {
                    self::assertSafeUrl($fields[$key]);
                }
            }
        }

        return $fields;
    }

    /**
     * Only https, except for loopback addresses (local testing).
     */
    public static function assertSafeUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || ! ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)))) {
            throw new RuntimeException(__('sso.error_https', ['url' => $url]));
        }
    }

    public function redirect(SsoProvider $provider, Request $request, string $mode = self::MODE_LOGIN): string
    {
        self::assertSafeUrl((string) $provider->authorize_url);

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put(self::SESSION, [
            'provider' => $provider->id,
            'mode' => $mode,
            'user' => $mode === self::MODE_LINK ? $request->user()?->id : null,
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'at' => time(),
        ]);

        $params = [
            'response_type' => 'code',
            'client_id' => $provider->client_id,
            'redirect_uri' => $provider->redirectUri(),
            'scope' => implode(' ', $provider->scopeList()),
            'state' => $state,
        ];
        if ($provider->isOidc()) {
            $params['nonce'] = $nonce;
        }
        if ($provider->use_pkce) {
            $params['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $params['code_challenge_method'] = 'S256';
        }

        $url = (string) $provider->authorize_url;

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Mode of the started flow (login or link), without consuming it.
     */
    public static function pendingMode(Request $request): string
    {
        return (string) ($request->session()->get(self::SESSION.'.mode') ?: self::MODE_LOGIN);
    }

    /**
     * Validates the callback and returns the claims, the subject and a display label.
     *
     * @return array{claims: array<string, mixed>, subject: string, label: ?string, mode: string, user: ?int}
     */
    public function callback(SsoProvider $provider, Request $request): array
    {
        // pull(): a flow can only be completed once.
        $flow = (array) $request->session()->pull(self::SESSION, []);

        if ($request->filled('error')) {
            throw new RuntimeException(__('sso.error_provider', ['error' => Str::limit((string) $request->query('error_description', $request->query('error')), 200)]));
        }
        if (($flow['provider'] ?? null) !== $provider->id || time() - (int) ($flow['at'] ?? 0) > self::FLOW_TTL
            || ! hash_equals((string) ($flow['state'] ?? ''), (string) $request->query('state', ''))) {
            throw new RuntimeException(__('sso.error_state'));
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            throw new RuntimeException(__('sso.error_no_code'));
        }

        $tokens = $this->exchange($provider, $code, (string) $flow['verifier']);
        $claims = [];
        $idToken = is_string($tokens['id_token'] ?? null) ? $tokens['id_token'] : null;

        if ($idToken !== null) {
            $claims = $this->validateIdToken($provider, $idToken, (string) $flow['nonce']);
        } elseif ($provider->isOidc()) {
            // OIDC requires an ID token in the token response (OIDC Core 3.1.3.3).
            throw new RuntimeException(__('sso.error_no_id_token'));
        }

        $access = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : null;
        if ((string) $provider->userinfo_url !== '' && $access !== null) {
            $info = $this->userinfo($provider, (string) $provider->userinfo_url, $access);
            if (isset($claims['sub'], $info['sub']) && (string) $claims['sub'] !== (string) $info['sub']) {
                throw new RuntimeException(__('sso.error_userinfo_sub'));
            }
            $claims = array_merge($claims, $info);
        }

        // GitHub: the e-mail is only in /user when public; /user/emails has the primary one.
        if ($provider->isGithub() && $access !== null && ! is_string($claims['email'] ?? null)) {
            $claims['email'] = $this->githubPrimaryEmail($provider, $access);
        }

        $subject = (string) data_get($claims, $provider->subject_claim ?: 'sub');
        if ($subject === '') {
            throw new RuntimeException(__('sso.error_claim_missing', ['claim' => $provider->subject_claim ?: 'sub']));
        }
        $label = data_get($claims, $provider->label_claim ?: 'email') ?: data_get($claims, $provider->email_claim ?: 'email');

        return [
            'claims' => $claims,
            'subject' => $subject,
            'label' => is_scalar($label) ? Str::limit((string) $label, 250, '') : null,
            'mode' => (string) ($flow['mode'] ?? self::MODE_LOGIN),
            'user' => isset($flow['user']) ? (int) $flow['user'] : null,
        ];
    }

    /**
     * Login: only accounts that linked this provider before.
     */
    public function findUser(SsoProvider $provider, string $subject): ?User
    {
        $identity = UserIdentity::query()->where('sso_provider_id', $provider->id)->where('subject', $subject)->first();
        // A personal provider only ever logs in its owner.
        if ($identity && $provider->isPersonal() && $identity->user_id !== $provider->owner_user_id) {
            return null;
        }
        $identity?->forceFill(['last_login_at' => now()])->save();

        return $identity?->user;
    }

    /**
     * Links the subject to the user. One identity per provider and user; a subject
     * already linked to another account is refused.
     */
    public function link(SsoProvider $provider, User $user, string $subject, ?string $label): UserIdentity
    {
        if ($provider->isPersonal() && $provider->owner_user_id !== $user->id) {
            throw new RuntimeException(__('sso.error_state'));
        }
        $existing = UserIdentity::query()->where('sso_provider_id', $provider->id)->where('subject', $subject)->first();
        if ($existing && $existing->user_id !== $user->id) {
            throw new RuntimeException(__('sso.error_linked_elsewhere'));
        }

        return UserIdentity::query()->updateOrCreate(
            ['sso_provider_id' => $provider->id, 'user_id' => $user->id],
            ['subject' => $subject, 'label' => $label],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function exchange(SsoProvider $provider, string $code, string $verifier): array
    {
        self::assertSafeUrl((string) $provider->token_url);

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $provider->redirectUri(),
        ];
        if ($provider->use_pkce) {
            $form['code_verifier'] = $verifier;
        }

        $http = OutboundUrl::client((string) $provider->token_url, $provider->isPersonal(), 15)->asForm();
        if ($provider->token_auth === 'basic') {
            $http = $http->withBasicAuth(rawurlencode((string) $provider->client_id), rawurlencode((string) $provider->client_secret));
        } else {
            $form['client_id'] = $provider->client_id;
            $form['client_secret'] = $provider->client_secret;
        }

        $response = $http->post((string) $provider->token_url, $form);
        OutboundUrl::assertSize($response);
        $data = (array) $response->json();

        if (! $response->successful() || ! isset($data['access_token'])) {
            throw new RuntimeException(__('sso.error_token', [
                'status' => $response->status(),
                'error' => isset($data['error']) && is_string($data['error']) ? $data['error'] : '-',
            ]));
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function userinfo(SsoProvider $provider, string $url, string $accessToken): array
    {
        self::assertSafeUrl($url);

        $response = OutboundUrl::client($url, $provider->isPersonal(), 15)
            ->withHeaders(['User-Agent' => self::userAgent()])
            ->withToken($accessToken)->get($url);
        OutboundUrl::assertSize($response);

        if (! $response->successful()) {
            throw new RuntimeException(__('sso.error_userinfo', ['status' => $response->status()]));
        }

        return (array) $response->json();
    }

    private function githubPrimaryEmail(SsoProvider $provider, string $accessToken): ?string
    {
        try {
            $emails = $this->userinfo($provider, 'https://api.github.com/user/emails', $accessToken);
        } catch (RuntimeException) {
            return null;
        }

        foreach ($emails as $entry) {
            if (is_array($entry) && ($entry['primary'] ?? false) && ($entry['verified'] ?? false) && is_string($entry['email'] ?? null)) {
                return $entry['email'];
            }
        }

        return null;
    }

    private static function userAgent(): string
    {
        return Str::slug(AppSettings::appName()) ?: 'bierkasse';
    }

    /**
     * @return array<string, mixed>
     */
    private function validateIdToken(SsoProvider $provider, string $jwt, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException(__('sso.error_id_token_format'));
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $claims = json_decode((string) base64_decode($payload, true), true);
        if (! is_array($claims)) {
            throw new RuntimeException(__('sso.error_id_token_format'));
        }

        $issuer = (string) $provider->issuer;
        // The issuer check is mandatory: without a configured issuer the token cannot be trusted.
        if ($issuer === '') {
            throw new RuntimeException(__('sso.error_issuer_missing'));
        }
        // Microsoft multi-tenant ("common"/"organizations"): the issuer contains {tenantid}.
        if (str_contains($issuer, '{tenantid}')) {
            $tid = (string) ($claims['tid'] ?? '');
            if (! preg_match('/^[0-9a-f-]{36}$/i', $tid)) {
                throw new RuntimeException(__('sso.error_id_token_iss'));
            }
            $issuer = str_replace('{tenantid}', $tid, $issuer);
        }
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($issuer, '/')) {
            throw new RuntimeException(__('sso.error_id_token_iss'));
        }
        $audience = array_map('strval', (array) ($claims['aud'] ?? []));
        if (! in_array((string) $provider->client_id, $audience, true)) {
            throw new RuntimeException(__('sso.error_id_token_aud'));
        }
        // OIDC Core 3.1.3.7: with several audiences the authorized party must be this client.
        if (count($audience) > 1 && (string) ($claims['azp'] ?? '') !== (string) $provider->client_id) {
            throw new RuntimeException(__('sso.error_id_token_azp'));
        }
        if ((int) ($claims['exp'] ?? 0) < time() - 60) {
            throw new RuntimeException(__('sso.error_id_token_exp'));
        }
        // The nonce was sent with every OIDC request, so it must come back unchanged.
        if ($provider->isOidc() && ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException(__('sso.error_id_token_nonce'));
        }

        return $claims;
    }
}

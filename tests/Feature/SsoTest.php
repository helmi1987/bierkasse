<?php

namespace Tests\Feature;

use App\Models\SsoProvider;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Sso;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use RefreshDatabase;

    private SsoProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = SsoProvider::query()->create([
            'name' => 'Firmen-SSO',
            'slug' => 'firma',
            'type' => 'oidc',
            'enabled' => true,
            'issuer' => 'https://sso.example/realms/office',
            'authorize_url' => 'https://sso.example/auth',
            'token_url' => 'https://sso.example/token',
            'client_id' => 'bier-client',
            'client_secret' => 'top-secret',
            'scopes' => 'openid profile email',
            'use_pkce' => true,
            'label_claim' => 'email',
        ]);
    }

    public function test_login_page_shows_active_provider(): void
    {
        $this->get('/')->assertOk()->assertSee('Mit Firmen-SSO anmelden');
    }

    public function test_redirect_contains_state_nonce_and_pkce(): void
    {
        $query = $this->startFlow('/login/sso/firma');

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('bier-client', $query['client_id']);
        $this->assertSame(route('login.sso.callback', 'firma'), $query['redirect_uri']);
        $this->assertSame(40, strlen($query['state']));
        $this->assertSame(40, strlen($query['nonce']));
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function test_user_links_provider_and_then_logs_in_with_it(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->get('/konto/einstellungen')->assertOk()->assertSee('Mit Firmen-SSO verknüpfen');
        $query = $this->startFlow('/konto/sso/firma/verknuepfen');
        $this->fakeTokenEndpoint($this->claims(['nonce' => $query['nonce']]));

        $this->get('/login/sso/firma/callback?code=abc&state='.$query['state'])
            ->assertRedirect(route('account.settings'))->assertSessionHas('status');
        $this->assertDatabaseHas('user_identities', ['user_id' => $user->id, 'sso_provider_id' => $this->provider->id, 'subject' => 'sub-123', 'label' => 'hans@example.org']);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://sso.example/token'
            && $r['code'] === 'abc' && strlen((string) $r['code_verifier']) === 64);

        // Login with the linked identity.
        $this->post('/abmelden');
        $this->assertGuest();
        $query = $this->startFlow('/login/sso/firma');
        $this->fakeTokenEndpoint($this->claims(['nonce' => $query['nonce']]));
        $this->get('/login/sso/firma/callback?code=abc&state='.$query['state'])->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_without_linked_account_is_refused(): void
    {
        $this->user(['email' => 'hans@example.org']);
        $query = $this->startFlow('/login/sso/firma');
        $this->fakeTokenEndpoint($this->claims(['nonce' => $query['nonce']]));

        $this->get('/login/sso/firma/callback?code=abc&state='.$query['state'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertSame(0, UserIdentity::query()->count());
    }

    public function test_sso_login_asks_totp_when_enabled(): void
    {
        $user = $this->withTotp($this->user());
        UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $this->provider->id, 'subject' => 'sub-123']);
        $query = $this->startFlow('/login/sso/firma');
        $this->fakeTokenEndpoint($this->claims(['nonce' => $query['nonce']]));

        $this->get('/login/sso/firma/callback?code=abc&state='.$query['state'])->assertRedirect(route('login.totp'));
        $this->assertGuest();
        $this->post('/anmelden/totp', ['code' => $this->currentCode($user)])->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_sso_login_of_locked_user_is_refused_with_contact(): void
    {
        $user = $this->user(['status' => User::STATUS_LOCKED]);
        UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $this->provider->id, 'subject' => 'sub-123']);
        $query = $this->startFlow('/login/sso/firma');
        $this->fakeTokenEndpoint($this->claims(['nonce' => $query['nonce']]));

        $this->get('/login/sso/firma/callback?code=abc&state='.$query['state'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['username' => __('auth.locked', ['email' => AppSettings::legalEmail()])]);
        $this->assertGuest();
    }

    public function test_subject_linked_to_other_user_cannot_be_linked_again(): void
    {
        $other = $this->user();
        UserIdentity::query()->create(['user_id' => $other->id, 'sso_provider_id' => $this->provider->id, 'subject' => 'sub-123']);
        $user = $this->user();
        $this->actingAs($user);

        $query = $this->startFlow('/konto/sso/firma/verknuepfen');
        $this->fakeTokenEndpoint($this->claims(['nonce' => $query['nonce']]));
        $this->get('/login/sso/firma/callback?code=abc&state='.$query['state'])
            ->assertRedirect(route('account.settings'))->assertSessionHasErrors('sso');

        $this->assertSame(0, $user->identities()->count());
    }

    public function test_user_unlinks_identity(): void
    {
        $user = $this->user();
        $identity = UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $this->provider->id, 'subject' => 'x']);
        $foreign = UserIdentity::query()->create(['user_id' => $this->user()->id, 'sso_provider_id' => $this->provider->id, 'subject' => 'y']);

        $this->actingAs($user)->delete("/konto/sso/{$foreign->id}")->assertNotFound();
        $this->delete("/konto/sso/{$identity->id}")->assertSessionHas('status');
        $this->assertSame(1, UserIdentity::query()->count());
    }

    public function test_state_mismatch_and_nonce_mismatch_are_rejected(): void
    {
        $user = $this->user();
        UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $this->provider->id, 'subject' => 'sub-123']);

        $this->fakeTokenEndpoint($this->claims());
        $this->startFlow('/login/sso/firma');
        $this->get('/login/sso/firma/callback?code=abc&state=wrong')->assertSessionHasErrors('username');
        Http::assertNothingSent();

        $query = $this->startFlow('/login/sso/firma');
        $this->fakeTokenEndpoint($this->claims(['nonce' => 'other']));
        $this->get('/login/sso/firma/callback?code=abc&state='.$query['state'])->assertSessionHasErrors('username');

        $query = $this->startFlow('/login/sso/firma');
        $this->fakeTokenEndpoint($this->claims(['nonce' => $query['nonce'], 'aud' => 'someone-else']));
        $this->get('/login/sso/firma/callback?code=abc&state='.$query['state'])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_github_login_uses_user_and_emails_endpoints(): void
    {
        $github = SsoProvider::query()->create(array_merge(Sso::preset('github'), [
            'name' => 'GitHub', 'slug' => 'github', 'type' => 'github', 'enabled' => true,
            'client_id' => 'gh-client', 'client_secret' => 'gh-secret', 'use_pkce' => true,
        ]));
        $this->assertTrue($github->isUsable());
        $this->assertFalse($github->isOidc());

        $user = $this->user();
        $this->actingAs($user);
        $query = $this->startFlow('/konto/sso/github/verknuepfen', 'https://github.com/login/oauth/authorize?');
        $this->assertArrayNotHasKey('nonce', $query);

        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'gh-token', 'token_type' => 'bearer']),
            'https://api.github.com/user' => Http::response(['id' => 4242, 'login' => 'hansgh', 'email' => null]),
            'https://api.github.com/user/emails' => Http::response([
                ['email' => 'old@example.org', 'primary' => false, 'verified' => true],
                ['email' => 'hans@example.org', 'primary' => true, 'verified' => true],
            ]),
        ]);

        $this->get('/login/sso/github/callback?code=abc&state='.$query['state'])->assertRedirect(route('account.settings'));
        $this->assertDatabaseHas('user_identities', ['user_id' => $user->id, 'sso_provider_id' => $github->id, 'subject' => '4242', 'label' => 'hansgh']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.github.com/user/emails' && $r->hasHeader('Authorization', 'Bearer gh-token'));
    }

    public function test_facebook_link_and_login_use_graph_api(): void
    {
        $facebook = SsoProvider::query()->create(array_merge(Sso::preset('facebook'), [
            'name' => 'Facebook', 'slug' => 'facebook', 'type' => 'facebook', 'enabled' => true,
            'client_id' => 'fb-app', 'client_secret' => 'fb-secret', 'use_pkce' => true,
        ]));
        $this->assertTrue($facebook->isUsable());
        $this->assertFalse($facebook->isOidc());

        Http::fake([
            'https://graph.facebook.com/v19.0/oauth/access_token' => Http::response(['access_token' => 'fb-token', 'token_type' => 'bearer', 'expires_in' => 5000]),
            'https://graph.facebook.com/v19.0/me*' => Http::response(['id' => '10223344', 'name' => 'Hans Muster', 'email' => 'hans@example.org']),
        ]);

        $user = $this->user();
        $this->actingAs($user);
        $this->get('/konto/einstellungen')->assertOk()->assertSee('Mit Facebook verknüpfen')->assertSee('sso-facebook');
        $query = $this->startFlow('/konto/sso/facebook/verknuepfen', 'https://www.facebook.com/v19.0/dialog/oauth?');
        $this->assertSame('fb-app', $query['client_id']);
        $this->assertSame('email public_profile', $query['scope']);
        $this->assertSame(route('login.sso.callback', 'facebook'), $query['redirect_uri']);
        $this->assertArrayNotHasKey('nonce', $query);

        $this->get('/login/sso/facebook/callback?code=abc&state='.$query['state'])->assertRedirect(route('account.settings'));
        $this->assertDatabaseHas('user_identities', ['user_id' => $user->id, 'sso_provider_id' => $facebook->id, 'subject' => '10223344', 'label' => 'Hans Muster']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://graph.facebook.com/v19.0/oauth/access_token'
            && $r['code'] === 'abc' && $r['client_id'] === 'fb-app' && $r['client_secret'] === 'fb-secret');
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v19.0/me?fields=id')
            && $r->hasHeader('Authorization', 'Bearer fb-token'));

        // Login with the linked Facebook identity.
        $this->post('/abmelden');
        $this->assertGuest();
        $this->get('/')->assertOk()->assertSee('Mit Facebook anmelden')->assertSee('sso-facebook');
        $query = $this->startFlow('/login/sso/facebook', 'https://www.facebook.com/v19.0/dialog/oauth?');
        $this->get('/login/sso/facebook/callback?code=abc&state='.$query['state'])->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_creates_facebook_provider_with_preset(): void
    {
        $this->actingAs($this->admin())->get('/admin/sso/create?type=facebook')->assertOk()
            ->assertSee('developers.facebook.com')->assertSee('/login/sso/', false);

        $this->post('/admin/sso', [
            'type' => 'facebook', 'name' => 'Facebook', 'client_id' => 'fb-app', 'client_secret' => 'fb-secret',
            'enabled' => '1', 'use_pkce' => '1', 'scopes' => 'x', 'token_auth' => 'post',
            'subject_claim' => 'sub', 'email_claim' => 'email', 'label_claim' => 'email',
        ])->assertSessionHas('status');

        $p = SsoProvider::query()->where('slug', 'facebook')->firstOrFail();
        $this->assertTrue($p->isUsable());
        $this->assertSame('email public_profile', $p->scopes);
        $this->assertSame('id', $p->subject_claim);
        $this->assertSame('https://www.facebook.com/v19.0/dialog/oauth', $p->authorize_url);
    }

    public function test_account_hint_without_active_provider(): void
    {
        $this->provider->forceFill(['enabled' => false])->save();

        $this->actingAs($this->user())->get('/konto/einstellungen')->assertOk()
            ->assertSee('Keine Verknüpfung.')->assertSee('Der Admin kann Anmeldung mit Google, GitHub usw. aktivieren.')
            ->assertDontSee('Noch kein Anbieter aktiv.');

        $this->actingAs($this->admin())->get('/konto/einstellungen')->assertOk()
            ->assertSee('Noch kein Anbieter aktiv. Google, Microsoft, GitHub oder Facebook unter Admin > SSO einrichten.')
            ->assertSee(route('backend.sso.index'));
    }

    public function test_active_provider_hides_no_provider_hint(): void
    {
        $this->actingAs($this->admin())->get('/konto/einstellungen')->assertOk()
            ->assertSee('Mit Firmen-SSO verknüpfen')->assertSee('sso-oidc')
            ->assertDontSee('Noch kein Anbieter aktiv.');
    }

    public function test_microsoft_common_tenant_issuer(): void
    {
        $tid = '9188040d-6c67-4c5b-b112-36a304b66dad';
        $ms = SsoProvider::query()->create([
            'name' => 'Microsoft', 'slug' => 'microsoft', 'type' => 'microsoft', 'tenant' => 'common', 'enabled' => true,
            'issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0',
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'client_id' => 'ms-client', 'client_secret' => 'ms-secret', 'scopes' => 'openid profile email', 'use_pkce' => true,
        ]);
        $user = $this->user();
        UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $ms->id, 'subject' => 'ms-sub']);

        $query = $this->startFlow('/login/sso/microsoft', 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?');
        $this->fakeTokenEndpoint([
            'iss' => "https://login.microsoftonline.com/{$tid}/v2.0", 'tid' => $tid, 'aud' => 'ms-client',
            'sub' => 'ms-sub', 'exp' => time() + 300, 'nonce' => $query['nonce'],
        ], 'https://login.microsoftonline.com/common/oauth2/v2.0/token');

        $this->get('/login/sso/microsoft/callback?code=abc&state='.$query['state'])->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_microsoft_preset_uses_tenant(): void
    {
        $preset = Sso::preset('microsoft', 'contoso.onmicrosoft.com');
        $this->assertSame('https://login.microsoftonline.com/contoso.onmicrosoft.com/v2.0/.well-known/openid-configuration', $preset['discovery_url']);
        $this->assertStringContainsString('/common/', Sso::preset('microsoft', '')['discovery_url']);
    }

    public function test_admin_creates_google_provider_via_discovery(): void
    {
        Http::fake([
            'https://accounts.google.com/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://accounts.google.com',
                'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_endpoint' => 'https://oauth2.googleapis.com/token',
                'userinfo_endpoint' => 'https://openidconnect.googleapis.com/v1/userinfo',
            ]),
        ]);

        $this->actingAs($this->admin())->post('/admin/sso', [
            'type' => 'google', 'name' => 'Google', 'client_id' => 'g-id', 'client_secret' => 'g-secret',
            'enabled' => '1', 'use_pkce' => '1', 'scopes' => 'x', 'token_auth' => 'post',
            'subject_claim' => 'sub', 'email_claim' => 'email', 'label_claim' => 'email',
        ])->assertSessionHas('status');

        $p = SsoProvider::query()->where('slug', 'google')->firstOrFail();
        $this->assertTrue($p->isUsable());
        $this->assertSame('https://accounts.google.com', $p->issuer);
        $this->assertSame('openid profile email', $p->scopes);
        $this->assertStringNotContainsString('g-secret', (string) $p->getRawOriginal('client_secret'));
    }

    public function test_plain_http_endpoints_are_refused(): void
    {
        $this->expectException(RuntimeException::class);
        Sso::assertSafeUrl('http://sso.example/token');
    }

    public function test_disabled_provider_returns_404(): void
    {
        $this->provider->forceFill(['enabled' => false])->save();

        $this->get('/login/sso/firma')->assertNotFound();
    }

    /**
     * @return array<string, string>
     */
    private function startFlow(string $url, string $prefix = 'https://sso.example/auth?'): array
    {
        $location = (string) $this->get($url)->headers->get('Location');
        $this->assertStringStartsWith($prefix, $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    private function claims(array $overrides = []): array
    {
        return array_merge([
            'iss' => 'https://sso.example/realms/office',
            'aud' => 'bier-client',
            'sub' => 'sub-123',
            'exp' => time() + 300,
            'iat' => time(),
            'email' => 'hans@example.org',
        ], $overrides);
    }

    /** @var array<string, string> Current ID token per token endpoint. */
    private array $jwts = [];

    /**
     * Fakes the token endpoint; later calls replace the returned ID token (Http::fake stubs accumulate).
     */
    private function fakeTokenEndpoint(array $claims, string $url = 'https://sso.example/token'): void
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $registered = isset($this->jwts[$url]);
        $this->jwts[$url] = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode($claims)).'.'.$b64('signature');

        if (! $registered) {
            Http::fake([
                $url => fn () => Http::response(['access_token' => 'access', 'token_type' => 'Bearer', 'id_token' => $this->jwts[$url]]),
            ]);
        }
    }
}

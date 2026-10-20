<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\SsoProvider;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Sso;
use App\Support\AppSettings;
use App\Support\OutboundUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PersonalSsoTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://auth.example.org/application/o';

    protected function setUp(): void
    {
        parent::setUp();

        OutboundUrl::resolveUsing(fn (string $host) => match ($host) {
            'auth.example.org' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
            'intern.example.org' => ['192.168.1.5'],
            'mixed.example.org' => ['93.184.215.14', '10.1.2.3'],
            'v6local.example.org' => ['fd00::1'],
            default => [],
        });
    }

    public function test_user_adds_provider_via_discovery_and_links_it(): void
    {
        Http::fake([
            self::BASE.'/bier/.well-known/openid-configuration' => Http::response([
                'issuer' => self::BASE.'/bier/',
                'authorization_endpoint' => self::BASE.'/authorize/',
                'token_endpoint' => self::BASE.'/token/',
                'userinfo_endpoint' => self::BASE.'/userinfo/',
            ]),
        ]);
        $user = $this->user();
        $this->actingAs($user);

        $page = $this->get('/konto/einstellungen')->assertOk()->assertSee('Eigener Anmelde-Anbieter');
        preg_match('#http://localhost/login/sso/(p-[a-z0-9]{12})/callback#', $page->getContent(), $m);
        $this->assertNotEmpty($m, 'redirect URI shown before saving');

        $this->put('/konto/eigener-anbieter', [
            'name' => 'Mein Authentik',
            'discovery_url' => self::BASE.'/bier/',
            'client_id' => 'my-client',
            'client_secret' => 'my-secret',
        ])->assertSessionHas('status');

        $p = SsoProvider::query()->where('owner_user_id', $user->id)->firstOrFail();
        $this->assertSame($m[1], $p->slug, 'slug matches the redirect URI shown before');
        $this->assertTrue($p->isPersonal());
        $this->assertTrue($p->isUsable());
        $this->assertSame(self::BASE.'/bier/', $p->issuer);
        $this->assertSame(self::BASE.'/token/', $p->token_url);
        $this->assertStringNotContainsString('my-secret', (string) $p->getRawOriginal('client_secret'));

        $this->get('/konto/einstellungen')->assertSee('nicht verbunden')->assertSee($p->redirectUri());

        $query = $this->startFlow('/konto/eigener-anbieter/verbinden');
        $this->fakeToken($this->claims(['nonce' => $query['nonce']]), ['sub' => 'ak-1', 'preferred_username' => 'hans']);
        $this->get("/login/sso/{$p->slug}/callback?code=abc&state=".$query['state'])
            ->assertRedirect(route('account.settings'))->assertSessionHas('status');

        $this->assertDatabaseHas('user_identities', ['user_id' => $user->id, 'sso_provider_id' => $p->id, 'subject' => 'ak-1']);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::BASE.'/token/' && strlen((string) $r['code_verifier']) === 64);
        $this->get('/konto/einstellungen')->assertSee('verbunden')->assertSee(route('login.personal.user', $user->username));
    }

    public function test_user_logs_in_with_personal_provider(): void
    {
        $user = $this->user(['username' => 'hans']);
        $p = $this->personalProvider($user);
        UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $p->id, 'subject' => 'ak-1']);

        $query = $this->startFlow('/login/eigener/hans');
        $this->fakeToken($this->claims(['nonce' => $query['nonce']]));
        $this->get("/login/sso/{$p->slug}/callback?code=abc&state=".$query['state'])->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);

        // Same via the form on the login page.
        $this->post('/abmelden');
        $this->assertGuest();
        $location = (string) $this->post('/login/eigener', ['username' => 'Hans '])->headers->get('Location');
        $this->assertStringStartsWith(self::BASE.'/authorize/?', $location);
    }

    public function test_totp_is_required_after_personal_login(): void
    {
        $user = $this->withTotp($this->user(['username' => 'hans']));
        $p = $this->personalProvider($user);
        UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $p->id, 'subject' => 'ak-1']);

        $query = $this->startFlow('/login/eigener/hans');
        $this->fakeToken($this->claims(['nonce' => $query['nonce']]));
        $this->get("/login/sso/{$p->slug}/callback?code=abc&state=".$query['state'])->assertRedirect(route('login.totp'));
        $this->assertGuest();
        $this->post('/anmelden/totp', ['code' => $this->currentCode($user)])->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_identity_of_other_user_cannot_log_in_via_personal_provider(): void
    {
        $owner = $this->user(['username' => 'hans']);
        $victim = $this->user(['username' => 'boss']);
        $p = $this->personalProvider($owner);
        UserIdentity::query()->create(['user_id' => $owner->id, 'sso_provider_id' => $p->id, 'subject' => 'ak-1']);
        // Should never exist; even if it does, it must not log in the other user.
        UserIdentity::query()->create(['user_id' => $victim->id, 'sso_provider_id' => $p->id, 'subject' => 'ak-boss']);

        $query = $this->startFlow('/login/eigener/hans');
        $this->fakeToken($this->claims(['nonce' => $query['nonce'], 'sub' => 'ak-boss']));
        $this->get("/login/sso/{$p->slug}/callback?code=abc&state=".$query['state'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('username');
        $this->assertGuest();

        // A non-owner cannot start a flow with it, and linking to a non-owner is refused.
        $this->actingAs($victim)->get("/konto/sso/{$p->slug}/verknuepfen")->assertNotFound();
        $this->get("/login/sso/{$p->slug}")->assertNotFound();
        $this->expectException(RuntimeException::class);
        app(Sso::class)->link($p, $victim, 'x', null);
    }

    public function test_personal_provider_is_not_shown_to_others(): void
    {
        $owner = $this->user();
        $p = $this->personalProvider($owner, ['name' => 'Geheimer-Anbieter']);

        $this->get('/')->assertOk()->assertDontSee('Geheimer-Anbieter')->assertSee('Mit eigenem Anbieter anmelden');
        $this->assertFalse(SsoProvider::usable()->contains('id', $p->id));

        $this->actingAs($this->user())->get('/konto/einstellungen')->assertOk()->assertDontSee('Geheimer-Anbieter');

        $admin = $this->admin();
        $this->actingAs($admin)->get('/backend/sso')->assertOk()->assertDontSee('Geheimer-Anbieter');
        $this->get("/backend/sso/{$p->id}/edit")->assertNotFound();
        $this->put("/backend/sso/{$p->id}", ['type' => 'oidc', 'name' => 'x'])->assertNotFound();
        $this->delete("/backend/sso/{$p->id}")->assertNotFound();
        $this->get("/backend/benutzer/{$owner->id}")->assertOk()->assertSee('Geheimer-Anbieter');
        $this->assertDatabaseHas('sso_providers', ['id' => $p->id]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'plain http' => ['http://auth.example.org/application/o/bier/'],
            'loopback v4' => ['https://127.0.0.1/application/o/bier/'],
            'private 10/8' => ['https://10.0.0.5/.well-known/openid-configuration'],
            'loopback v6' => ['https://[::1]/application/o/bier/'],
            'metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'private by DNS' => ['https://intern.example.org/'],
            'one private record' => ['https://mixed.example.org/'],
            'ULA v6 by DNS' => ['https://v6local.example.org/'],
            'unresolvable' => ['https://nowhere.example.org/'],
            'credentials in URL' => ['https://user:pw@auth.example.org/'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_ssrf_discovery_url_is_rejected(string $url): void
    {
        Http::fake();
        $this->actingAs($this->user())->put('/konto/eigener-anbieter', [
            'name' => 'X', 'discovery_url' => $url, 'client_id' => 'c', 'client_secret' => 's',
        ])->assertSessionHasErrors('personal_sso');

        Http::assertNothingSent();
        $this->assertSame(0, SsoProvider::query()->count());
    }

    #[DataProvider('unsafeUrls')]
    public function test_ssrf_manual_endpoints_are_rejected(string $url): void
    {
        Http::fake();
        $this->actingAs($this->user())->put('/konto/eigener-anbieter', [
            'name' => 'X', 'issuer' => 'https://auth.example.org', 'client_id' => 'c', 'client_secret' => 's',
            'authorize_url' => self::BASE.'/authorize/', 'token_url' => $url,
        ])->assertSessionHasErrors('personal_sso');

        Http::assertNothingSent();
        $this->assertSame(0, SsoProvider::query()->count());
    }

    public function test_ssrf_discovery_returning_internal_endpoint_is_rejected(): void
    {
        Http::fake([
            self::BASE.'/bier/.well-known/openid-configuration' => Http::response([
                'issuer' => self::BASE.'/bier/',
                'authorization_endpoint' => self::BASE.'/authorize/',
                'token_endpoint' => 'https://169.254.169.254/latest/meta-data/',
            ]),
        ]);

        $this->actingAs($this->user())->put('/konto/eigener-anbieter', [
            'name' => 'X', 'discovery_url' => self::BASE.'/bier/', 'client_id' => 'c', 'client_secret' => 's',
        ])->assertSessionHasErrors('personal_sso');
        $this->assertSame(0, SsoProvider::query()->count());
    }

    public function test_ssrf_redirects_and_large_responses_are_not_followed(): void
    {
        Http::fake([
            self::BASE.'/redirect/.well-known/openid-configuration' => Http::response('', 302, ['Location' => 'http://127.0.0.1/']),
            self::BASE.'/big/.well-known/openid-configuration' => Http::response(str_repeat('x', OutboundUrl::MAX_BYTES + 1)),
        ]);
        $this->actingAs($this->user());

        foreach (['redirect', 'big'] as $path) {
            $this->put('/konto/eigener-anbieter', [
                'name' => 'X', 'discovery_url' => self::BASE."/{$path}/", 'client_id' => 'c', 'client_secret' => 's',
            ])->assertSessionHasErrors('personal_sso');
        }
        $this->assertSame(0, SsoProvider::query()->count());
        Http::assertSentCount(2);
    }

    public function test_ssrf_is_checked_again_at_login_time(): void
    {
        $user = $this->user(['username' => 'hans']);
        $p = $this->personalProvider($user);
        UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $p->id, 'subject' => 'ak-1']);
        Http::fake();

        $query = $this->startFlow('/login/eigener/hans');
        // DNS changed to an internal address after saving (rebinding).
        OutboundUrl::resolveUsing(fn () => ['10.0.0.1']);
        $this->get("/login/sso/{$p->slug}/callback?code=abc&state=".$query['state'])->assertSessionHasErrors('username');
        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_public_ip_check(): void
    {
        foreach (['127.0.0.1', '10.1.1.1', '172.16.0.1', '192.168.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1',
            '::1', '::', 'fe80::1', 'fc00::1', 'fd12::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a00:1', 'ff02::1', 'not-an-ip'] as $ip) {
            $this->assertFalse(OutboundUrl::isPublicIp($ip), $ip);
        }
        foreach (['93.184.215.14', '8.8.8.8', '2606:4700:4700::1111', '2a00:1450:4001::1'] as $ip) {
            $this->assertTrue(OutboundUrl::isPublicIp($ip), $ip);
        }
    }

    public function test_unknown_user_or_missing_provider_gives_same_generic_message(): void
    {
        $this->user(['username' => 'ohne']);
        $linked = $this->user(['username' => 'unverbunden']);
        $this->personalProvider($linked);

        foreach (['gibtsnicht', 'ohne', 'unverbunden'] as $name) {
            $this->post('/login/eigener', ['username' => $name])->assertRedirect(route('login.personal'))
                ->assertSessionHasErrors(['username' => __('sso.personal.login_unavailable')]);
        }
    }

    public function test_personal_login_is_rate_limited(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login/eigener', ['username' => 'user'.$i])
                ->assertSessionHasErrors(['username' => __('sso.personal.login_unavailable')]);
        }

        $throttled = ['username' => __('auth.throttle', ['seconds' => 60])];
        $this->post('/login/eigener', ['username' => 'another'])->assertSessionHasErrors($throttled);
        $this->get('/login/eigener/another')->assertRedirect(route('login.personal'))->assertSessionHasErrors($throttled);
    }

    public function test_admin_can_disable_personal_providers(): void
    {
        $user = $this->user(['username' => 'hans']);
        $p = $this->personalProvider($user);
        Setting::put(AppSettings::PERSONAL_SSO, false);

        $this->get('/')->assertDontSee('Mit eigenem Anbieter anmelden');
        $this->get('/login/eigener')->assertNotFound();
        $this->get('/login/eigener/hans')->assertNotFound();
        $this->get("/login/sso/{$p->slug}/callback?code=a&state=b")->assertNotFound();
        $this->actingAs($user)->get('/konto/eigener-anbieter/verbinden')->assertNotFound();
        $this->put('/konto/eigener-anbieter', ['name' => 'x', 'client_id' => 'c'])->assertNotFound();
    }

    public function test_user_deletes_personal_provider(): void
    {
        $user = $this->user();
        $p = $this->personalProvider($user);
        UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $p->id, 'subject' => 'ak-1']);

        $this->actingAs($user)->delete('/konto/eigener-anbieter')->assertSessionHas('status');
        $this->assertSame(0, SsoProvider::query()->count());
        $this->assertSame(0, UserIdentity::query()->count());
    }

    public function test_changing_the_issuer_removes_the_link(): void
    {
        $user = $this->user();
        $p = $this->personalProvider($user);
        UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $p->id, 'subject' => 'ak-1']);

        $this->actingAs($user)->put('/konto/eigener-anbieter', [
            'name' => 'Neu', 'issuer' => 'https://auth.example.org/other/', 'client_id' => 'my-client',
            'authorize_url' => self::BASE.'/authorize/', 'token_url' => self::BASE.'/token/',
        ])->assertSessionHas('status');
        $this->assertSame(0, UserIdentity::query()->count());
        $this->assertSame('my-secret', $p->fresh()->client_secret, 'empty secret keeps the stored one');
    }

    private function personalProvider(User $owner, array $attributes = []): SsoProvider
    {
        $p = new SsoProvider(array_merge([
            'name' => 'Mein Authentik',
            'slug' => 'p-'.strtolower(substr(md5((string) $owner->id), 0, 12)),
            'type' => 'oidc',
            'enabled' => true,
            'issuer' => self::BASE.'/bier/',
            'authorize_url' => self::BASE.'/authorize/',
            'token_url' => self::BASE.'/token/',
            'client_id' => 'my-client',
            'client_secret' => 'my-secret',
            'scopes' => 'openid profile email',
            'use_pkce' => true,
        ], $attributes));
        $p->forceFill(['owner_user_id' => $owner->id])->save();

        return $p;
    }

    /**
     * @return array<string, string>
     */
    private function startFlow(string $url): array
    {
        $location = (string) $this->get($url)->headers->get('Location');
        $this->assertStringStartsWith(self::BASE.'/authorize/?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    private function claims(array $overrides = []): array
    {
        return array_merge([
            'iss' => self::BASE.'/bier/',
            'aud' => 'my-client',
            'sub' => 'ak-1',
            'exp' => time() + 300,
            'iat' => time(),
        ], $overrides);
    }

    private function fakeToken(array $claims, ?array $userinfo = null): void
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $jwt = $b64(json_encode(['alg' => 'RS256'])).'.'.$b64(json_encode($claims)).'.'.$b64('sig');

        Http::fake([
            self::BASE.'/token/' => Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $jwt]),
            self::BASE.'/userinfo/' => Http::response($userinfo ?? ['sub' => $claims['sub']]),
        ]);
    }
}

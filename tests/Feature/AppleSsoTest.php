<?php

namespace Tests\Feature;

use App\Models\SsoProvider;
use App\Models\UserIdentity;
use App\Services\AppleClientSecret;
use App\Services\Sso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppleSsoTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICES_ID = 'ch.example.bierkasse.login';

    private const TEAM_ID = 'ABCDE12345';

    private const KEY_ID = 'KEY0123456';

    private string $pem;

    private string $publicKey;

    private SsoProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $this->pem = (string) $pem;
        $this->publicKey = (string) openssl_pkey_get_details($key)['key'];

        $this->provider = SsoProvider::query()->create(array_merge([
            'name' => 'Apple',
            'slug' => 'apple',
            'type' => 'apple',
            'enabled' => true,
            'client_id' => self::SERVICES_ID,
            'client_secret' => $this->pem,
            'apple_team_id' => self::TEAM_ID,
            'apple_key_id' => self::KEY_ID,
        ], Sso::preset('apple')));
    }

    public function test_client_secret_is_valid_es256_jwt(): void
    {
        $now = time();
        $jwt = AppleClientSecret::create(self::TEAM_ID, self::KEY_ID, self::SERVICES_ID, $this->pem, $now);
        [$header, $claims, $signature] = $this->decode($jwt);

        $this->assertSame(['alg' => 'ES256', 'kid' => self::KEY_ID], $header);
        $this->assertSame([
            'iss' => self::TEAM_ID,
            'iat' => $now,
            'exp' => $now + 300,
            'aud' => 'https://appleid.apple.com',
            'sub' => self::SERVICES_ID,
        ], $claims);
        $this->assertSame(64, strlen($signature));

        [$h, $c] = explode('.', $jwt);
        $this->assertSame(1, openssl_verify($h.'.'.$c, $this->rawToDer($signature), $this->publicKey, OPENSSL_ALGO_SHA256));
        $this->assertSame(0, openssl_verify($h.'.'.$c.'x', $this->rawToDer($signature), $this->publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_provider_is_usable_oidc_without_userinfo_and_shows_apple_button(): void
    {
        $this->assertTrue($this->provider->isUsable());
        $this->assertTrue($this->provider->isOidc());

        $this->get('/')->assertOk()->assertSee('Mit Apple anmelden')->assertSee('sso-apple', false);

        $this->provider->forceFill(['apple_key_id' => null])->save();
        $this->assertFalse($this->provider->refresh()->isUsable());
    }

    public function test_authorize_redirect_uses_form_post_and_nonce_without_pkce(): void
    {
        $query = $this->startFlow();

        $this->assertSame('form_post', $query['response_mode']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(self::SERVICES_ID, $query['client_id']);
        $this->assertSame('name email', $query['scope']);
        $this->assertSame(route('login.sso.callback', 'apple'), $query['redirect_uri']);
        $this->assertSame(40, strlen($query['nonce']));
        $this->assertArrayNotHasKey('code_challenge', $query);
        $this->assertArrayNotHasKey('code_challenge_method', $query);
    }

    public function test_post_callback_bounces_to_get_callback(): void
    {
        $response = $this->post('/login/sso/apple/callback', [
            'code' => 'c0de', 'state' => 'st4te', 'user' => '{"name":{"firstName":"Hans"}}', 'other' => 'x',
        ]);

        $response->assertStatus(303);
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(route('login.sso.callback', 'apple').'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['code' => 'c0de', 'state' => 'st4te'], $query);
        // No session cookie is set (it would replace the browser's session).
        $this->assertSame([], $response->headers->getCookies());

        $response = $this->post('/login/sso/apple/callback', ['error' => 'user_cancelled_authorize', 'state' => str_repeat('a', 300)]);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['error' => 'user_cancelled_authorize'], $query);
    }

    public function test_post_callback_is_404_for_other_provider_types(): void
    {
        SsoProvider::query()->create([
            'name' => 'Firmen-SSO', 'slug' => 'firma', 'type' => 'oidc', 'enabled' => true,
            'issuer' => 'https://sso.example', 'authorize_url' => 'https://sso.example/auth', 'token_url' => 'https://sso.example/token',
            'client_id' => 'bier-client', 'client_secret' => 'top-secret', 'scopes' => 'openid profile email',
        ]);

        $this->post('/login/sso/firma/callback', ['code' => 'c', 'state' => 's'])->assertNotFound();
        $this->post('/login/sso/unbekannt/callback', ['code' => 'c', 'state' => 's'])->assertNotFound();
    }

    public function test_linked_user_logs_in_with_apple(): void
    {
        $user = $this->user();
        UserIdentity::query()->create(['user_id' => $user->id, 'sso_provider_id' => $this->provider->id, 'subject' => '001234.abcdef.1234']);

        $query = $this->startFlow();
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $idToken = $b64(json_encode(['alg' => 'RS256', 'kid' => 'test']))
            .'.'.$b64(json_encode([
                'iss' => 'https://appleid.apple.com',
                'aud' => self::SERVICES_ID,
                'sub' => '001234.abcdef.1234',
                'exp' => time() + 600,
                'iat' => time(),
                'nonce' => $query['nonce'],
                'email' => 'abc123@privaterelay.appleid.com',
            ])).'.'.$b64('signature');
        Http::fake([
            'https://appleid.apple.com/auth/token' => Http::response(['access_token' => 'access', 'token_type' => 'Bearer', 'id_token' => $idToken]),
        ]);

        $location = (string) $this->post('/login/sso/apple/callback', ['code' => 'apple-code', 'state' => $query['state']])
            ->assertStatus(303)->headers->get('Location');
        $this->get($location)->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);

        Http::assertSent(function (HttpRequest $r) {
            if ($r->url() !== 'https://appleid.apple.com/auth/token') {
                return false;
            }
            [$header, $claims, $signature] = $this->decode((string) $r['client_secret']);
            [$h, $c] = explode('.', (string) $r['client_secret']);

            return $r['code'] === 'apple-code' && $r['client_id'] === self::SERVICES_ID && ! isset($r['code_verifier'])
                && $header['kid'] === self::KEY_ID && $claims['iss'] === self::TEAM_ID && $claims['sub'] === self::SERVICES_ID
                && openssl_verify($h.'.'.$c, $this->rawToDer($signature), $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
        });
    }

    public function test_admin_form_validates_apple_fields(): void
    {
        $this->actingAs($this->admin())->get('/admin/sso/create?type=apple')->assertOk()
            ->assertSee('Team ID')->assertSee('Key ID')->assertSee('(.p8)', false)->assertSee('/login/sso/', false)
            ->assertDontSee('id="discovery_url"', false)->assertDontSee('id="userinfo_url"', false);

        $form = [
            'type' => 'apple', 'name' => 'Apple Login', 'slug' => 'apple-neu', 'client_id' => self::SERVICES_ID,
            'apple_team_id' => self::TEAM_ID, 'apple_key_id' => self::KEY_ID, 'client_secret' => $this->pem, 'enabled' => '1',
        ];

        $this->post('/admin/sso', ['apple_team_id' => 'abcde12345'] + $form)->assertSessionHasErrors('apple_team_id');
        $this->post('/admin/sso', ['apple_key_id' => 'KEY01'] + $form)->assertSessionHasErrors('apple_key_id');

        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($rsa, $rsaPem);
        $this->post('/admin/sso', ['client_secret' => $rsaPem] + $form)->assertSessionHas('error', __('sso.apple_key_invalid'));
        $this->assertFalse(SsoProvider::query()->where('slug', 'apple-neu')->exists());

        $this->post('/admin/sso', $form)->assertSessionHas('status');
        $p = SsoProvider::query()->where('slug', 'apple-neu')->firstOrFail();
        $this->assertTrue($p->isUsable());
        $this->assertFalse($p->use_pkce);
        $this->assertSame('post', $p->token_auth);
        $this->assertSame('name email', $p->scopes);
        $this->assertSame('https://appleid.apple.com', $p->issuer);
        $this->assertNull($p->userinfo_url);

        // Empty key field on edit keeps the stored key.
        $this->put('/admin/sso/'.$p->id, ['client_secret' => '', 'apple_key_id' => 'NEWKEY1234'] + $form)->assertSessionHas('status');
        $p->refresh();
        $this->assertSame('NEWKEY1234', $p->apple_key_id);
        $this->assertSame(trim($this->pem), $p->client_secret);
    }

    /**
     * @return array<string, string>
     */
    private function startFlow(): array
    {
        $location = (string) $this->get('/login/sso/apple')->headers->get('Location');
        $this->assertStringStartsWith('https://appleid.apple.com/auth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
     */
    private function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts);
        $dec = fn (string $s) => (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);

        return [json_decode($dec($parts[0]), true), json_decode($dec($parts[1]), true), $dec($parts[2])];
    }

    /**
     * Raw R||S (2 x 32 bytes) back to a DER ECDSA signature for openssl_verify.
     */
    private function rawToDer(string $raw): string
    {
        $int = function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || ord($bytes[0]) > 0x7F) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".chr(strlen($bytes)).$bytes;
        };
        $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32, 32));

        return "\x30".chr(strlen($seq)).$seq;
    }
}

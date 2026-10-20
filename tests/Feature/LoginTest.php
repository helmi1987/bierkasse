<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_is_the_login_page(): void
    {
        $this->get('/')->assertOk()->assertSee('Anmelden')->assertSee('Passwort vergessen');
    }

    public function test_logged_in_user_on_root_goes_to_dashboard(): void
    {
        $this->actingAs($this->user())->get('/')->assertRedirect(route('account.dashboard'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/konto')->assertRedirect(route('login'));
    }

    public function test_login_with_password(): void
    {
        $user = $this->user(['username' => 'hans']);

        $this->post('/anmelden', ['username' => 'Hans', 'password' => 'secret-password'])
            ->assertRedirect(route('account.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->get('/konto')->assertOk()->assertSee('CHF 0.00');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->user(['username' => 'hans']);

        $this->from('/')->post('/anmelden', ['username' => 'hans', 'password' => 'wrong'])
            ->assertRedirect('/')->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->user(['username' => 'hans']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/anmelden', ['username' => 'hans', 'password' => 'wrong']);
        }
        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_left_user_cannot_login(): void
    {
        $this->user(['username' => 'hans', 'status' => User::STATUS_LEFT]);

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_left_user_session_is_ended(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $user->forceFill(['status' => User::STATUS_LEFT])->save();

        $this->get('/konto')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_totp_second_step(): void
    {
        $user = $this->withTotp($this->user(['username' => 'hans']));

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])
            ->assertRedirect(route('login.totp'));
        $this->assertGuest();

        $this->post('/anmelden/totp', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/anmelden/totp', ['code' => $this->currentCode($user)])
            ->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_totp_code_cannot_be_replayed(): void
    {
        $user = $this->withTotp($this->user(['username' => 'hans']));
        $code = $this->currentCode($user);

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password']);
        $this->post('/anmelden/totp', ['code' => $code])->assertRedirect(route('account.dashboard'));
        $this->post('/abmelden');

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password']);
        $this->post('/anmelden/totp', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_user_can_enable_totp_in_settings(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/konto/einstellungen')->assertOk()->assertSee('data:image/svg+xml;base64,', false);

        $secret = session('totp.setup_secret');
        $code = Totp::code($secret, Totp::currentStep());
        $this->post('/konto/totp', ['code' => $code])->assertRedirect(route('account.settings'))
            ->assertSessionHas('totp.recovery_codes');

        $this->assertTrue($user->fresh()->hasTotp());
    }

    public function test_must_change_password_redirects_to_settings(): void
    {
        $user = $this->user(['username' => 'hans', 'must_change_password' => true]);

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])
            ->assertRedirect(route('account.settings'));
        $this->get('/konto')->assertRedirect(route('account.settings'));

        $this->put('/konto/passwort', [
            'current_password' => 'secret-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertRedirect(route('account.settings'));

        $this->assertFalse($user->fresh()->must_change_password);
        $this->get('/konto')->assertOk();
    }

    public function test_security_headers_are_set(): void
    {
        $response = $this->get('/');
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}

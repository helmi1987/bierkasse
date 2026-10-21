<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $overrides = []): array
    {
        return array_merge([
            'code' => 'durst',
            'username' => 'neu.user',
            'first_name' => 'Neu',
            'last_name' => '',
            'email' => '',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ], $overrides);
    }

    private function open(): void
    {
        Setting::put(AppSettings::REGISTRATION_ENABLED, true);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
    }

    public function test_registration_without_password_setting_is_closed(): void
    {
        $this->get('/registrieren')->assertOk()->assertSee('geschlossen');
        $this->post('/registrieren', $this->data())->assertForbidden();
        $this->assertSame(0, User::query()->count());
    }

    public function test_registration_can_be_switched_off(): void
    {
        $this->open();
        Setting::put(AppSettings::REGISTRATION_ENABLED, false);

        $this->post('/registrieren', $this->data())->assertForbidden();
    }

    public function test_wrong_registration_password_is_rejected(): void
    {
        $this->open();

        $this->from('/registrieren')->post('/registrieren', $this->data(['code' => 'falsch']))
            ->assertRedirect('/registrieren')->assertSessionHasErrors('code');
        $this->assertSame(0, User::query()->count());
    }

    public function test_registration_with_password_creates_user(): void
    {
        $this->open();

        $this->post('/registrieren', $this->data())->assertRedirect(route('account.dashboard'));

        $user = User::query()->where('username', 'neu.user')->first();
        $this->assertNotNull($user);
        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertNull($user->email);
        $this->assertAuthenticatedAs($user);
    }

    public function test_username_rules(): void
    {
        $this->open();
        $this->user(['username' => 'taken']);

        foreach (['ab', 'Bad Name', 'ümlaut', str_repeat('a', 33), 'taken'] as $name) {
            $this->post('/registrieren', $this->data(['username' => $name]))->assertSessionHasErrors('username');
        }
        $this->assertSame(1, User::query()->count());
    }

    public function test_qr_code_parameter_prefills_password(): void
    {
        $this->open();

        $this->get('/registrieren?code=durst')->assertOk()->assertSee('value="durst"', false)
            ->assertSee('Nur nötig für Passwort-Reset per Mail');
    }

    public function test_special_characters_in_registration_password(): void
    {
        $passwords = ['asdf33.?_2%', '!Bier#2026&', '#start', 'ende#', '&a=b', '+plus+', '%20x%', '"quote"', "<tag>'", 'Ümlaut€?', 'a\\b/c', '?frage?'];

        foreach ($passwords as $i => $password) {
            Setting::put(AppSettings::REGISTRATION_ENABLED, true);
            Setting::put(AppSettings::REGISTRATION_PASSWORD, $password);
            Setting::flushCache();

            $this->get('/registrieren?'.http_build_query(['code' => $password]))->assertOk()
                ->assertSee('value="'.e($password).'"', false);
            $this->post('/registrieren', $this->data(['code' => $password, 'username' => 'sonder'.$i]))
                ->assertRedirect(route('account.dashboard'));
            $this->app['auth']->guard()->logout();
        }
        $this->assertSame(count($passwords), User::query()->where('username', 'like', 'sonder%')->count());
    }

    public function test_code_fields_are_not_altered_by_phone_keyboards(): void
    {
        $this->open();
        $this->get('/registrieren')->assertSee('id="code" name="code" value="" autocomplete="off" autocapitalize="none" autocorrect="off"', false);
        $this->actingAs($this->admin())->get('/backend/einstellungen')->assertSee('autocapitalize="none" autocorrect="off"', false);
    }

    public function test_many_newcomers_from_one_office_ip_can_register(): void
    {
        $this->open();

        for ($i = 0; $i < 20; $i++) {
            $this->post('/registrieren', $this->data(['username' => 'kollege'.$i]))->assertRedirect(route('account.dashboard'));
            $this->app['auth']->guard()->logout();
        }
        $this->assertSame(20, User::query()->count());
    }

    public function test_registration_is_rate_limited(): void
    {
        $this->open();

        for ($i = 0; $i < 10; $i++) {
            $this->post('/registrieren', $this->data(['code' => 'falsch', 'username' => 'user'.$i]));
        }
        $this->post('/registrieren', $this->data())->assertSessionHasErrors('code');
        $this->assertSame(0, User::query()->count());
    }

    public function test_admin_sees_printable_flyer(): void
    {
        $this->open();
        $this->actingAs($this->admin());

        $this->get('/backend/registrierung-qr?view=flyer')->assertOk()
            ->assertSee('class="flyer"', false)->assertSee('Die Bierkasse ist jetzt digital!')
            ->assertSee('Schluss mit Strichliste und Münzsuche.')->assertSee('code=durst')
            ->assertSee('Zum Home-Bildschirm')->assertSee('data-print', false);
        $this->get('/backend/registrierung-qr?view=flyer&code=0')->assertOk()
            ->assertSee('class="flyer"', false)->assertDontSee('code=durst')
            ->assertSee('Registrierungspasswort gibt');
        $this->get('/backend/registrierung-qr')->assertOk()->assertDontSee('class="flyer"', false);
    }

    public function test_admin_sees_registration_qr(): void
    {
        $this->open();
        $this->actingAs($this->admin());

        $this->get('/backend/registrierung-qr')->assertOk()->assertSee('data:image/svg+xml;base64,', false)
            ->assertSee('code=durst')->assertSee('enthält das Registrierungspasswort');
        $this->get('/backend/registrierung-qr?code=0')->assertOk()->assertDontSee('code=durst')
            ->assertDontSee('enthält das Registrierungspasswort');
    }
}

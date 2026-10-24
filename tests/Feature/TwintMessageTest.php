<?php

namespace Tests\Feature;

use App\Models\QrCode;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwintMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::putMany([AppSettings::APP_NAME => 'Vereinskasse', AppSettings::TWINT_PHONE => '079 123 45 67']);
    }

    public function test_dashboard_shows_default_message_with_app_name_and_username(): void
    {
        $this->actingAs($this->user(['username' => 'hans.muster']));

        $this->get('/konto')->assertOk()
            ->assertSee('<p class="twint-message-text" id="twint-message" data-select-all>Vereinskasse: Einzahlung von Username: hans.muster</p>', false)
            ->assertSee('data-copy="#twint-message"', false);
    }

    public function test_custom_message_replaces_placeholders_and_is_escaped(): void
    {
        Setting::put(AppSettings::TWINT_MESSAGE, 'Bier {username} <b>{appname}</b>');
        $this->actingAs($this->user(['username' => 'hans.muster']));

        $this->assertSame('Bier gast <b>Vereinskasse</b>', AppSettings::twintMessage('gast'));
        $this->get('/konto')->assertOk()
            ->assertSee('Bier hans.muster &lt;b&gt;Vereinskasse&lt;/b&gt;', false)
            ->assertDontSee('Einzahlung von Username');
    }

    public function test_no_message_when_twint_is_off(): void
    {
        Setting::put(AppSettings::TWINT_ENABLED, false);
        $this->actingAs($this->user());

        $this->get('/konto')->assertOk()->assertDontSee('id="twint-message"', false);
    }

    public function test_guest_pages_show_message_for_gast(): void
    {
        Setting::putMany([AppSettings::GUEST_ENABLED => true, AppSettings::GUEST_PASSWORD => 'gast-1234', AppSettings::CASHBOX_QR_ENABLED => true]);
        $expected = 'Vereinskasse: Einzahlung von Username: gast';

        $this->get('/gast?code=gast-1234')->assertOk()->assertSee($expected);

        QrCode::ensureFirst(QrCode::KIND_TWINT);
        $this->get('/kasse/'.QrCode::newestActive(QrCode::KIND_TWINT)->code)->assertOk()->assertSee($expected);

        QrCode::ensureFirst(QrCode::KIND_CASH);
        $this->get('/kasse/'.QrCode::newestActive(QrCode::KIND_CASH)->code)->assertOk()->assertDontSee($expected);
    }

    public function test_admin_saves_message_setting(): void
    {
        $this->actingAs($this->admin());
        $base = ['app_name' => 'Vereinskasse', 'beer_price' => '1.50', 'default_limit' => '0', 'registration_mode' => 'invite', 'mail_transport' => 'sendmail'];

        $this->get('/admin/einstellungen')->assertOk()
            ->assertSee('name="twint_message" value=""', false)
            ->assertSee('placeholder="'.e(AppSettings::DEFAULT_TWINT_MESSAGE).'"', false);

        $this->put('/admin/einstellungen', $base + ['twint_message' => '  {appname} / {username}  '])->assertSessionHasNoErrors();
        $this->assertSame('{appname} / {username}', AppSettings::twintMessageTemplate());
        $this->assertSame('Vereinskasse / gast', AppSettings::twintMessage('gast'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.update']);

        $this->put('/admin/einstellungen', $base + ['twint_message' => str_repeat('x', AppSettings::TWINT_MESSAGE_MAX + 1)])
            ->assertSessionHasErrors('twint_message');

        $this->put('/admin/einstellungen', $base + ['twint_message' => ''])->assertSessionHasNoErrors();
        $this->assertSame('Vereinskasse: Einzahlung von Username: gast', AppSettings::twintMessage('gast'));
    }

    public function test_registration_rejects_reserved_username_gast(): void
    {
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_PASSWORD);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        QrCode::ensureFirst(QrCode::KIND_FLYER);

        foreach (['gast', 'Gast', ' GAST '] as $name) {
            $this->post('/registrieren', [
                'code' => 'durst', 'username' => $name, 'first_name' => 'Neu', 'last_name' => '', 'email' => '',
                'password' => 'password-123', 'password_confirmation' => 'password-123', 'consent' => '1',
            ])->assertSessionHasErrors(['username' => __('validation.username_reserved')]);
        }
        $this->assertSame(0, User::query()->where('username', 'gast')->count());
    }
}

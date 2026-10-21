<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InviteTest extends TestCase
{
    use RefreshDatabase;

    private function open(): void
    {
        Setting::put(AppSettings::REGISTRATION_ENABLED, true);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
    }

    public function test_user_sees_invite_qr_with_code(): void
    {
        $this->open();
        $this->actingAs($this->user());

        $this->get('/konto')->assertOk()->assertSee(route('account.invite'))->assertSee('Jemand Neues einladen');
        $this->get('/konto/einladen')->assertOk()
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertSee(route('register').'?code=durst')
            ->assertSee('data-share="#invite-url"', false)
            ->assertSee('data-copy="#invite-url"', false);
    }

    public function test_disabled_registration_hides_invite(): void
    {
        $this->open();
        Setting::put(AppSettings::REGISTRATION_ENABLED, false);
        $this->actingAs($this->user());

        $this->get('/konto/einladen')->assertNotFound();
        $this->get('/konto')->assertOk()->assertDontSee(route('account.invite'));
    }

    public function test_missing_registration_password_hides_invite(): void
    {
        Setting::put(AppSettings::REGISTRATION_ENABLED, true);
        $this->actingAs($this->user());

        $this->get('/konto/einladen')->assertNotFound();
        $this->get('/konto')->assertOk()->assertDontSee('Jemand Neues einladen');
    }

    public function test_guests_cannot_see_invite(): void
    {
        $this->open();

        $this->get('/konto/einladen')->assertRedirect(route('login'));
    }
}

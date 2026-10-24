<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsSaveButtonsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = ['app_name' => 'Vereinskasse', 'beer_price' => '1.50', 'default_limit' => '0', 'registration_mode' => 'invite', 'mail_transport' => 'sendmail'];

    public function test_every_card_has_its_own_save_button(): void
    {
        $this->actingAs($this->admin());

        $response = $this->get('/admin/einstellungen')->assertOk();
        foreach (['general', 'undo', 'twint', 'registration', 'login', 'notify', 'mail'] as $section) {
            $response->assertSee('id="settings-'.$section.'"', false)
                ->assertSee('name="section" value="'.$section.'"', false);
        }
        $response->assertDontSee('btn primary big', false);
    }

    public function test_save_redirects_to_the_card_of_the_used_button(): void
    {
        $this->actingAs($this->admin());

        $this->put('/admin/einstellungen', self::BASE + ['section' => 'mail'])
            ->assertSessionHasNoErrors()->assertRedirect(route('backend.settings').'#settings-mail');
        $this->put('/admin/einstellungen', self::BASE + ['section' => 'bogus'])
            ->assertRedirect(route('backend.settings'));
        $this->put('/admin/einstellungen', self::BASE)
            ->assertRedirect(route('backend.settings'));
    }
}

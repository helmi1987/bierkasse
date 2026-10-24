<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\AppSettings;
use App\Support\SystemInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_with_version_and_default_copyright_on_guest_and_user_pages(): void
    {
        $version = SystemInfo::appVersion()['version'];
        $this->assertNotEmpty($version);
        $this->admin();

        $this->get('/')->assertOk()
            ->assertSee('<footer class="wrap site-footer">', false)
            ->assertSee('Bierkasse v'.$version)
            ->assertSee(AppSettings::DEFAULT_COPYRIGHT);

        $this->actingAs($this->user());
        $this->get('/konto')->assertOk()->assertSee('v'.$version)->assertSee(AppSettings::DEFAULT_COPYRIGHT);
    }

    public function test_footer_uses_app_name_setting(): void
    {
        $this->admin();
        Setting::put(AppSettings::APP_NAME, 'Vereinskasse');

        $this->get('/')->assertOk()->assertSee('Vereinskasse v'.SystemInfo::appVersion()['version']);
    }

    public function test_admin_changes_and_clears_copyright(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $base = ['beer_price' => '1.50', 'default_limit' => '0', 'registration_mode' => 'invite', 'mail_transport' => 'sendmail'];

        $this->get('/admin/einstellungen')->assertOk()->assertSee('name="copyright" value="'.e(AppSettings::DEFAULT_COPYRIGHT).'"', false);

        $this->put('/admin/einstellungen', $base + ['copyright' => '  © 2027 <b>Verein</b>  '])->assertSessionHasNoErrors();
        $this->assertSame('© 2027 <b>Verein</b>', AppSettings::copyright());
        $this->get('/konto/einstellungen')->assertOk()
            ->assertSee('© 2027 &lt;b&gt;Verein&lt;/b&gt;', false)->assertDontSee('<b>Verein</b>', false);

        $this->put('/admin/einstellungen', $base + ['copyright' => ''])->assertSessionHasNoErrors();
        $this->assertSame('', AppSettings::copyright());
        $this->get('/konto/einstellungen')->assertOk()
            ->assertDontSee(AppSettings::DEFAULT_COPYRIGHT)->assertDontSee('<span aria-hidden="true"> · </span><span>', false);

        $this->put('/admin/einstellungen', $base + ['copyright' => str_repeat('x', AppSettings::COPYRIGHT_MAX + 1)])
            ->assertSessionHasErrors('copyright');
    }
}

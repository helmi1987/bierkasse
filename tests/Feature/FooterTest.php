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
            ->assertSee('© '.now()->year.' Stefan Diethelm');

        $this->actingAs($this->user());
        $this->get('/konto')->assertOk()->assertSee('v'.$version)->assertSee('© '.now()->year.' Stefan Diethelm');
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
            ->assertDontSee('Stefan Diethelm')->assertDontSee('<span aria-hidden="true"> · </span><span>', false);

        $this->put('/admin/einstellungen', $base + ['copyright' => str_repeat('x', AppSettings::COPYRIGHT_MAX + 1)])
            ->assertSessionHasErrors('copyright');
    }

    public function test_placeholders_year_and_start_year_from_oldest_user(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 1));
        $old = $this->user();
        $old->forceFill(['created_at' => '2023-03-01 10:00:00'])->save();
        $this->user();
        Setting::put(AppSettings::COPYRIGHT, '© {startjahr}–{jahr} <i>X</i> {jahr}');

        $this->assertSame('© 2023–2026 <i>X</i> 2026', AppSettings::copyrightDisplay());
        $this->get('/')->assertOk()->assertSee('© 2023–2026 &lt;i&gt;X&lt;/i&gt; 2026', false);
    }

    public function test_range_collapses_when_years_equal(): void
    {
        $this->travelTo(now()->setDate(2026, 6, 1));
        $this->admin();
        $y = now()->year;
        Setting::put(AppSettings::COPYRIGHT, '© {startjahr}–{jahr} A');
        $this->assertSame("© $y A", AppSettings::copyrightDisplay());
        Setting::put(AppSettings::COPYRIGHT, '© {startjahr}-{jahr} A');
        $this->assertSame("© $y A", AppSettings::copyrightDisplay());
        Setting::put(AppSettings::COPYRIGHT, '© {startjahr} A');
        $this->assertSame("© $y A", AppSettings::copyrightDisplay());
    }

    public function test_start_year_falls_back_to_current_year_without_users(): void
    {
        Setting::put(AppSettings::COPYRIGHT, '{startjahr}');
        $this->assertSame((string) now()->year, AppSettings::copyrightDisplay());
    }
}

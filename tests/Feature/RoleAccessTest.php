<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_reach_backend(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->actingAs($user);

        foreach (['/admin', '/admin/kasse', '/admin/benutzer', "/admin/benutzer/{$other->id}", '/admin/statistik', '/admin/einstellungen', '/admin/sso', '/admin/registrierung-qr',
            '/admin/buchungen', '/admin/qr-codes', '/admin/protokoll', '/admin/kasse?tab=vorschlaege'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post("/admin/benutzer/{$other->id}/einzahlung", ['amount' => '100', 'method' => 'cash'])->assertForbidden();
        $this->put("/admin/benutzer/{$user->id}/rolle", ['role' => 'admin'])->assertForbidden();
        $this->assertSame(0, $other->balance());
        $this->assertSame(User::ROLE_USER, $user->fresh()->role);

        $this->get('/konto')->assertOk()->assertDontSee(route('backend.dashboard'));
    }

    public function test_kassier_reaches_backend_but_not_admin_pages(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        $this->actingAs($kassier);

        foreach (['/admin', '/admin/kasse', '/admin/kasse?tab=vorschlaege', '/admin/benutzer', "/admin/benutzer/{$user->id}", '/admin/benutzer?status=left',
            '/admin/benutzer?tab=einladungen', '/admin/buchungen', '/admin/buchungen?tab=gaeste', '/admin/qr-codes', '/admin/qr-codes/kassen-qr',
            '/admin/protokoll', '/admin/protokoll?tab=fassungen', '/admin/registrierung-qr'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (['/admin/einstellungen', '/admin/sso', '/admin/sso/create', '/admin/navigation', '/admin/feedback', '/admin/wartung'] as $url) {
            $this->get($url)->assertForbidden();
        }
        // Feature switches are admin-only since 1.20.0.
        $this->put('/admin/einstellungen/funktionen', ['rounds_enabled' => '0'])->assertForbidden();
        $this->put("/admin/benutzer/{$user->id}/rolle", ['role' => 'kassier'])->assertForbidden();
        $this->get('/konto')->assertOk()->assertSee(route('backend.dashboard'));
    }

    public function test_kassier_cannot_touch_admin_accounts(): void
    {
        $admin = $this->admin();
        $this->actingAs($this->kassier());

        $this->put("/admin/benutzer/{$admin->id}/passwort", ['password' => 'new-password', 'password_confirmation' => 'new-password'])->assertForbidden();
        $this->post("/admin/benutzer/{$admin->id}/status", ['active' => 0])->assertForbidden();
        $this->post("/admin/benutzer/{$admin->id}/totp-zuruecksetzen")->assertForbidden();
        $this->assertTrue($admin->fresh()->isActive());
    }

    public function test_admin_pages_and_role_change(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $this->actingAs($admin);

        foreach (['/admin/einstellungen', '/admin/sso', '/admin/sso/create?type=github', '/admin/sso/create?type=microsoft', '/admin/registrierung-qr'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->put("/admin/benutzer/{$user->id}/rolle", ['role' => 'kassier'])->assertSessionHas('status');
        $this->assertTrue($user->fresh()->isKassier());

        // The last admin keeps the role.
        $this->put("/admin/benutzer/{$admin->id}/rolle", ['role' => 'user'])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_badge_counts_open_items(): void
    {
        $kassier = $this->kassier();
        $a = $this->user();
        Ledger::reportDeposit($a, 1000, 'cash');
        Ledger::reportDeposit($this->user(), 500, 'twint');
        $b = $this->user();
        Ledger::bookDeposit($b, 300, 'cash', $kassier);
        Ledger::requestPayout($b);

        $this->assertSame(3, Ledger::pendingCount());
        $this->actingAs($kassier)->get('/konto')->assertOk()->assertSee('<span class="badge"', false)->assertSee('3 offene Posten');
    }

    public function test_kassier_resets_password_and_totp(): void
    {
        $user = $this->withTotp($this->user(['username' => 'hans']));
        $this->actingAs($this->kassier());

        $this->put("/admin/benutzer/{$user->id}/passwort", ['password' => 'temp-pass-1', 'password_confirmation' => 'temp-pass-1', 'must_change' => '1'])
            ->assertSessionHas('status');
        $this->post("/admin/benutzer/{$user->id}/totp-zuruecksetzen")->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertFalse($user->hasTotp());

        $this->post('/abmelden');
        $this->post('/anmelden', ['username' => 'hans', 'password' => 'temp-pass-1'])->assertRedirect(route('account.settings'));
    }

    public function test_kassier_deactivates_and_reactivates_user(): void
    {
        $user = $this->user();
        $this->actingAs($this->kassier());

        $this->post("/admin/benutzer/{$user->id}/status", ['active' => 0])->assertSessionHas('status');
        $this->assertFalse($user->fresh()->isActive());
        $this->post("/admin/benutzer/{$user->id}/status", ['active' => 1])->assertSessionHas('status');
        $this->assertTrue($user->fresh()->isActive());
    }

    public function test_twint_settings_offer_kassier_names_and_require_a_recipient(): void
    {
        User::factory()->create(['role' => User::ROLE_KASSIER, 'first_name' => 'Phlipp', 'last_name' => 'Arnold']);
        $this->actingAs($this->admin());
        $this->get('/admin/einstellungen')->assertOk()->assertSee('<option value="Phlipp Arnold">', false);

        $this->put('/admin/einstellungen', ['beer_price' => '1.50', 'default_limit' => '0', 'registration_mode' => 'invite',
            'mail_transport' => 'sendmail', 'twint_enabled' => '1', 'twint' => [['name' => '', 'phone' => '']]])
            ->assertSessionHasErrors('twint');
    }

    public function test_top_bar_kassier_and_backend(): void
    {
        $this->actingAs($this->admin());
        $this->get('/admin')->assertOk()->assertSeeText('Kassier')->assertSee(route('backend.settings'))
            ->assertSee(route('backend.audit'))->assertDontSee(route('backend.maintenance'));
        $this->get('/admin/einstellungen')->assertOk()->assertSee(route('backend.maintenance'))->assertDontSee(route('backend.cash'));

        $this->actingAs($this->kassier());
        $this->get('/admin')->assertOk()->assertSeeText('Kassier')->assertSee(route('backend.audit'))
            ->assertDontSee(route('backend.settings'));
    }

    public function test_kassier_and_admin_need_an_email(): void
    {
        $plain = $this->user(['email' => null]);
        $this->actingAs($this->admin());
        $this->put("/admin/benutzer/{$plain->id}/rolle", ['role' => 'kassier'])->assertSessionHas('error');
        $this->assertSame(User::ROLE_USER, $plain->fresh()->role);

        $kassier = $this->kassier();
        $this->actingAs($kassier);
        $this->put('/konto/profil', ['email' => ''])->assertSessionHasErrors('email');
        $this->assertNotNull($kassier->fresh()->email);
        $this->actingAs($plain);
        $this->put('/konto/profil', ['email' => ''])->assertSessionHasNoErrors();

        // Existing kassier without e-mail: hint only, access stays.
        $this->actingAs($this->kassier(['email' => null]));
        $this->get('/admin')->assertOk()->assertSee(__('account.email_missing'));
    }

    public function test_admin_saves_settings(): void
    {
        $this->actingAs($this->admin());

        $this->put('/admin/einstellungen', [
            'beer_price' => '2.00',
            'default_limit' => '10',
            'app_name' => 'Büro-Bier',
            'twint_enabled' => '1',
            'twint' => [['name' => 'Max', 'phone' => '079 000 00 00'], ['name' => '', 'phone' => ''], ['name' => 'Eva', 'phone' => '']],
            'registration_mode' => 'password',
            'registration_password' => 'prost',
            'notify_kassier' => '1',
            'mail_transport' => 'smtp',
            'smtp_host' => 'mail.example.org',
            'smtp_port' => 587,
            'smtp_user' => 'bier',
            'smtp_password' => 'smtp-secret',
            'smtp_encryption' => 'tls',
        ])->assertSessionHas('status');

        Setting::flushCache();
        $this->assertSame(200, AppSettings::beerPrice());
        $this->assertSame(1000, AppSettings::defaultLimit());
        $this->assertSame('Büro-Bier', AppSettings::appName());
        $this->assertSame([['name' => 'Max', 'phone' => '079 000 00 00'], ['name' => 'Eva', 'phone' => '']], AppSettings::twintRecipients());
        $this->assertTrue(AppSettings::twintEnabled());
        $this->assertTrue(AppSettings::registrationOpen());
        $this->assertSame('smtp-secret', AppSettings::mail()['smtp_password']);

        // Secrets are stored encrypted.
        foreach (Setting::query()->pluck('value') as $raw) {
            $this->assertStringNotContainsString('smtp-secret', (string) $raw);
            $this->assertStringNotContainsString('prost', (string) $raw);
        }

        // Admin can view the registration password; an empty SMTP password keeps the stored one.
        $this->get('/admin/einstellungen')->assertOk()->assertSee('value="prost"', false)->assertDontSee('smtp-secret');
        $this->put('/admin/einstellungen', ['beer_price' => '2', 'default_limit' => '0', 'registration_mode' => 'password', 'mail_transport' => 'sendmail'])->assertSessionHas('status');
        Setting::flushCache();
        $this->assertSame('smtp-secret', AppSettings::mail()['smtp_password']);
    }
}

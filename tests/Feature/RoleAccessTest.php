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

        foreach (['/backend', '/backend/kasse', '/backend/benutzer', "/backend/benutzer/{$other->id}", '/backend/statistik', '/backend/einstellungen', '/backend/sso', '/backend/registrierung-qr'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post("/backend/benutzer/{$other->id}/einzahlung", ['amount' => '100', 'method' => 'cash'])->assertForbidden();
        $this->put("/backend/benutzer/{$user->id}/rolle", ['role' => 'admin'])->assertForbidden();
        $this->assertSame(0, $other->balance());
        $this->assertSame(User::ROLE_USER, $user->fresh()->role);

        $this->get('/konto')->assertOk()->assertDontSee(route('backend.dashboard'));
    }

    public function test_kassier_reaches_backend_but_not_admin_pages(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        $this->actingAs($kassier);

        foreach (['/backend', '/backend/kasse', '/backend/benutzer', "/backend/benutzer/{$user->id}", '/backend/benutzer?status=left', '/backend/statistik'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (['/backend/einstellungen', '/backend/sso', '/backend/sso/create', '/backend/registrierung-qr'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->put("/backend/benutzer/{$user->id}/rolle", ['role' => 'kassier'])->assertForbidden();
        $this->get('/konto')->assertOk()->assertSee(route('backend.dashboard'));
    }

    public function test_kassier_cannot_touch_admin_accounts(): void
    {
        $admin = $this->admin();
        $this->actingAs($this->kassier());

        $this->put("/backend/benutzer/{$admin->id}/passwort", ['password' => 'new-password', 'password_confirmation' => 'new-password'])->assertForbidden();
        $this->post("/backend/benutzer/{$admin->id}/status", ['active' => 0])->assertForbidden();
        $this->post("/backend/benutzer/{$admin->id}/totp-zuruecksetzen")->assertForbidden();
        $this->assertTrue($admin->fresh()->isActive());
    }

    public function test_admin_pages_and_role_change(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $this->actingAs($admin);

        foreach (['/backend/einstellungen', '/backend/sso', '/backend/sso/create?type=github', '/backend/sso/create?type=microsoft', '/backend/registrierung-qr'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->put("/backend/benutzer/{$user->id}/rolle", ['role' => 'kassier'])->assertSessionHas('status');
        $this->assertTrue($user->fresh()->isKassier());

        // The last admin keeps the role.
        $this->put("/backend/benutzer/{$admin->id}/rolle", ['role' => 'user'])->assertSessionHas('error');
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

        $this->put("/backend/benutzer/{$user->id}/passwort", ['password' => 'temp-pass-1', 'password_confirmation' => 'temp-pass-1', 'must_change' => '1'])
            ->assertSessionHas('status');
        $this->post("/backend/benutzer/{$user->id}/totp-zuruecksetzen")->assertSessionHas('status');

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

        $this->post("/backend/benutzer/{$user->id}/status", ['active' => 0])->assertSessionHas('status');
        $this->assertFalse($user->fresh()->isActive());
        $this->post("/backend/benutzer/{$user->id}/status", ['active' => 1])->assertSessionHas('status');
        $this->assertTrue($user->fresh()->isActive());
    }

    public function test_admin_saves_settings(): void
    {
        $this->actingAs($this->admin());

        $this->put('/backend/einstellungen', [
            'beer_price' => '2.00',
            'default_limit' => '10',
            'app_name' => 'Büro-Bier',
            'twint_phone' => '079 000 00 00',
            'twint_name' => 'Max',
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
        $this->assertTrue(AppSettings::registrationOpen());
        $this->assertSame('smtp-secret', AppSettings::mail()['smtp_password']);

        // Secrets are stored encrypted.
        foreach (Setting::query()->pluck('value') as $raw) {
            $this->assertStringNotContainsString('smtp-secret', (string) $raw);
            $this->assertStringNotContainsString('prost', (string) $raw);
        }

        // Admin can view the registration password; an empty SMTP password keeps the stored one.
        $this->get('/backend/einstellungen')->assertOk()->assertSee('value="prost"', false)->assertDontSee('smtp-secret');
        $this->put('/backend/einstellungen', ['beer_price' => '2', 'default_limit' => '0', 'registration_mode' => 'password', 'mail_transport' => 'sendmail'])->assertSessionHas('status');
        Setting::flushCache();
        $this->assertSame('smtp-secret', AppSettings::mail()['smtp_password']);
    }
}

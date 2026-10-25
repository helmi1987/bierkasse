<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Feedback;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LegalArchive;
use App\Support\AppSettings;
use App\Support\LegalTexts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LegalConsentTest extends TestCase
{
    use RefreshDatabase;

    private function openRegistration(): void
    {
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_PASSWORD);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'code' => 'durst', 'username' => 'neu.user', 'password' => 'password-123', 'password_confirmation' => 'password-123',
        ], $overrides);
    }

    /** Consent form as shown: checkbox plus the displayed (current) revisions. */
    private function accept(array $overrides = []): array
    {
        return array_merge([
            'consent' => '1',
            'privacy_revision' => LegalArchive::current(LegalTexts::PRIVACY)->id,
            'terms_revision' => LegalArchive::current(LegalTexts::TERMS)->id,
        ], $overrides);
    }

    private function legalForm(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Stefan Diethelm',
            'legal_email' => 'bier@helminet.ch',
            'legal_location' => 'Schweiz',
            'privacy_text' => LegalTexts::defaultText(LegalTexts::PRIVACY),
            'terms_text' => LegalTexts::defaultText(LegalTexts::TERMS),
            'privacy_mode' => 'auto',
            'terms_mode' => 'auto',
        ], $overrides);
    }

    public function test_public_pages_are_reachable_logged_out_with_placeholders_and_date(): void
    {
        $this->admin();
        $this->setPrice(200);

        $this->get('/datenschutz')->assertOk()
            ->assertSee('Stand: '.LegalTexts::versionDate(LegalTexts::DEFAULT_VERSION))
            ->assertSee('<h1>Datenschutzerklärung</h1>', false)
            ->assertSee('<h2>1. Verantwortlich</h2>', false)
            ->assertSee('Stefan Diethelm, Privatperson, bier@helminet.ch')
            ->assertSee('Server-Standort: Schweiz')
            ->assertSee('<li>Kassier/Admin: alle Buchungen, Salden und das Protokoll</li>', false)
            ->assertDontSee('{email}');
        $this->get('/nutzungsbestimmungen')->assertOk()
            ->assertSee('Preis: CHF 2.00 pro Bier')
            ->assertSee('innerhalb von 10 Minuten')
            ->assertSee('bis CHF 0.00 ins Minus');
        // Footer links on the login page.
        $this->get('/')->assertOk()->assertSee(route('legal.privacy'))->assertSee(route('legal.terms'));
    }

    public function test_text_is_escaped(): void
    {
        $rendered = LegalTexts::renderText("Titel\n\n<script>alert(1)</script>\n- <b>x</b>");

        $this->assertSame('Titel', $rendered['title']);
        $this->assertStringNotContainsString('<script>', (string) $rendered['html']);
        $this->assertStringContainsString('&lt;script&gt;', (string) $rendered['html']);
        $this->assertStringContainsString('<li>&lt;b&gt;x&lt;/b&gt;</li>', (string) $rendered['html']);
    }

    public function test_guest_pages_link_both_texts(): void
    {
        $this->admin();
        Setting::put(AppSettings::GUEST_ENABLED, true);
        Setting::put(AppSettings::GUEST_PASSWORD, 'gast');

        $this->get('/gast')->assertOk()->assertSee('data-testid="legal-links"', false)->assertSee(route('legal.terms'));
    }

    public function test_registration_without_checkbox_fails(): void
    {
        Mail::fake();
        $this->openRegistration();

        $this->post('/registrieren', $this->registration())
            ->assertSessionHasErrors(['consent' => 'Bitte Nutzungsbestimmungen und Datenschutzerklärung akzeptieren']);
        $this->assertGuest();
        $this->assertNull(User::query()->where('username', 'neu.user')->first());
    }

    public function test_registration_with_checkbox_stores_versions(): void
    {
        Mail::fake();
        $this->openRegistration();
        $this->get('/registrieren')->assertOk()->assertSee('name="consent"', false)->assertSee('target="_blank"', false);

        $this->post('/registrieren', $this->registration(['consent' => '1']))->assertRedirect(route('account.dashboard'));

        $user = User::query()->where('username', 'neu.user')->firstOrFail();
        $this->assertSame(LegalTexts::DEFAULT_VERSION, $user->privacy_accepted_version);
        $this->assertSame(LegalTexts::DEFAULT_VERSION, $user->terms_accepted_version);
        $this->assertNotNull($user->consent_accepted_at);
        $audit = AuditLog::query()->where('action', 'registration')->latest('id')->firstOrFail();
        $this->assertSame(LegalTexts::DEFAULT_VERSION, json_decode($audit->new_values, true)['terms_accepted_version']);
        $this->get('/konto')->assertOk();
    }

    public function test_consent_gate_redirects_and_accepting_unblocks(): void
    {
        $user = User::factory()->withoutConsent()->create();
        $this->actingAs($user);

        $this->get('/konto')->assertRedirect(route('consent.show'));
        $this->get('/statistik')->assertRedirect(route('consent.show'));
        $this->post('/konto/bier', ['quantity' => 1])->assertRedirect(route('consent.show'));
        $this->post('/konto/feedback', ['type' => 'feedback', 'message' => 'Hallo Welt'])->assertRedirect(route('consent.show'));
        $this->assertSame(0, Feedback::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->get('/live', ['X-Live-Refresh' => '1'])->assertRedirect(route('consent.show'));
        $this->getJson('/live', ['X-Live-Refresh' => '1'])->assertRedirect(route('consent.show'));
        // Live/POST requests do not become the page to return to (still the last page view).
        $this->assertSame(route('stats'), session('url.intended'));
        // Public pages stay reachable.
        $this->get('/datenschutz')->assertOk();
        $this->get('/nutzungsbestimmungen')->assertOk();

        $this->get('/zustimmung')->assertOk()
            ->assertSee('data-testid="consent-privacy"', false)->assertSee('data-testid="consent-terms"', false)
            ->assertSee('Weiter')->assertSee('Abmelden');
        $this->post('/zustimmung', [])->assertSessionHasErrors('consent');
        $this->assertTrue($user->fresh()->needsConsent());

        $this->get('/statistik');
        $this->post('/zustimmung', $this->accept())->assertRedirect(route('stats'));
        $fresh = $user->fresh();
        $this->assertFalse($fresh->needsConsent());
        $this->assertNotNull($fresh->consent_accepted_at);
        $this->assertTrue(AuditLog::query()->where('action', 'consent.accept')->where('subject_id', $user->id)->exists());
        $this->get('/konto')->assertOk();
        $this->get('/zustimmung')->assertRedirect(route('account.dashboard'));
    }

    public function test_accept_with_outdated_versions_is_refused(): void
    {
        $user = User::factory()->withoutConsent()->create();
        $this->actingAs($user);
        $shown = LegalArchive::current(LegalTexts::TERMS)->id;
        $this->get('/zustimmung')->assertOk()
            ->assertSee('name="terms_revision" value="'.$shown.'"', false);

        // An admin forces a new terms version while the page is open.
        Setting::put(AppSettings::TERMS_VERSION, '2026-10-24 12:00:00');

        $this->post('/zustimmung', $this->accept(['terms_revision' => $shown]))
            ->assertRedirect(route('consent.show'))
            ->assertSessionHas('warning', 'Die Texte wurden geändert, bitte erneut lesen.');
        $this->assertNull($user->fresh()->consent_accepted_at);
        $this->assertFalse(AuditLog::query()->where('action', 'consent.accept')->exists());

        $this->post('/zustimmung', $this->accept())->assertRedirect();
        $this->assertSame('2026-10-24 12:00:00', $user->fresh()->terms_accepted_version);
    }

    public function test_admin_and_admin_only_accounts_are_gated_too(): void
    {
        $admin = User::factory()->admin()->withoutConsent()->create();
        $admin->forceFill(['admin_only' => true])->save();
        $this->actingAs($admin);

        $this->get('/admin')->assertRedirect(route('consent.show'));
        $this->post('/zustimmung', $this->accept())->assertRedirect(route('backend.dashboard'));
        $this->get('/admin')->assertOk();
    }

    public function test_password_change_comes_before_consent(): void
    {
        $user = User::factory()->withoutConsent()->create(['password' => 'secret-password', 'must_change_password' => true]);
        $this->actingAs($user);

        $this->get('/konto')->assertRedirect(route('account.settings'));
        $this->get('/konto/einstellungen')->assertOk();
        $this->put('/konto/passwort', ['current_password' => 'secret-password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->get('/konto/einstellungen')->assertRedirect(route('consent.show'));
    }

    public function test_login_of_user_without_consent_lands_on_consent_page(): void
    {
        User::factory()->withoutConsent()->create(['username' => 'hans', 'password' => 'secret-password']);

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'secret-password'])->assertRedirect(route('account.dashboard'));
        $this->get('/konto')->assertRedirect(route('consent.show'));
        $this->post('/abmelden')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_reaccept_after_version_bump(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $this->actingAs($admin);

        $this->put('/admin/einstellungen/rechtliches', $this->legalForm([
            'terms_text' => "Nutzungsbestimmungen\n\n1. Neu\nGanz neue Regeln.",
            'terms_mode' => 'reaccept',
        ]))->assertSessionHasNoErrors()->assertSessionHas('status', __('legal.saved_reaccept'));

        $this->assertNotSame(LegalTexts::DEFAULT_VERSION, LegalTexts::version(LegalTexts::TERMS));
        $this->assertSame(LegalTexts::DEFAULT_VERSION, LegalTexts::version(LegalTexts::PRIVACY));
        $this->assertTrue(AuditLog::query()->where('action', 'legal.update')->exists());
        $this->get('/nutzungsbestimmungen')->assertSee('Ganz neue Regeln.')->assertSee('Stand: '.LegalTexts::versionDate(LegalTexts::version(LegalTexts::TERMS)));

        // Everybody, the admin included, has to accept again.
        $this->get('/admin')->assertRedirect(route('consent.show'));
        $this->actingAs($user);
        $this->get('/konto')->assertRedirect(route('consent.show'));
        $this->get('/zustimmung')->assertOk()->assertSee(__('legal.consent_renewal'));
        $this->post('/zustimmung', $this->accept());
        $this->assertSame(LegalTexts::version(LegalTexts::TERMS), $user->fresh()->terms_accepted_version);
        $this->get('/konto')->assertOk();
    }

    public function test_editorial_change_does_not_force_reaccept(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $this->actingAs($admin);

        $this->put('/admin/einstellungen/rechtliches', $this->legalForm([
            'legal_email' => 'kasse@example.org',
            'privacy_text' => "Datenschutzerklärung\n\nTippfehler korrigiert, {email}.",
            'privacy_mode' => 'silent',
        ]))->assertSessionHasNoErrors()->assertSessionHas('status', __('legal.saved'));

        $this->assertSame(LegalTexts::DEFAULT_VERSION, LegalTexts::version(LegalTexts::PRIVACY));
        $this->get('/datenschutz')->assertSee('Tippfehler korrigiert, kasse@example.org.');
        $this->get('/admin')->assertOk();
        $this->actingAs($user);
        $this->get('/konto')->assertOk();
    }

    public function test_contact_email_is_required_and_only_admins_edit(): void
    {
        $this->actingAs($this->admin());
        $this->put('/admin/einstellungen/rechtliches', $this->legalForm(['legal_email' => '']))->assertSessionHasErrors('legal_email');
        $this->get('/admin/einstellungen')->assertOk()->assertSee(__('legal.mode_reaccept'));

        $this->actingAs($this->kassier());
        $this->put('/admin/einstellungen/rechtliches', $this->legalForm())->assertForbidden();
    }

    public function test_backend_user_detail_shows_consent(): void
    {
        $this->actingAs($this->kassier());
        $done = $this->user();
        $open = User::factory()->withoutConsent()->create();

        $this->get(route('backend.users.show', $done))->assertOk()
            ->assertSee('Datenschutzerklärung · Stand '.LegalTexts::versionDate(LegalTexts::DEFAULT_VERSION));
        $this->get(route('backend.users.show', $open))->assertOk()->assertSee('data-testid="consent-info"', false)->assertSee('noch offen');
    }
}

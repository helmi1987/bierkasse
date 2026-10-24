<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\LegalTexts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LegalNoticeTest extends TestCase
{
    use RefreshDatabase;

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

    private function settingsForm(array $overrides = []): array
    {
        return array_merge([
            'beer_price' => '1.50',
            'default_limit' => '0.00',
            'undo_minutes' => ['consume' => 10, 'round' => 10, 'guest' => 10, 'gift' => 5],
            'registration_mode' => 'invite',
            'mail_transport' => 'sendmail',
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
    }

    public function test_silent_mode_only_sets_last_changed(): void
    {
        $this->actingAs($this->admin());
        $this->put('/backend/einstellungen/rechtliches', $this->legalForm(['terms_text' => 'Neu', 'terms_mode' => 'silent']))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(LegalTexts::changedAt(LegalTexts::TERMS));
        $this->assertNull(LegalTexts::noticeAt(LegalTexts::TERMS));
        $this->assertSame(LegalTexts::DEFAULT_VERSION, LegalTexts::version(LegalTexts::TERMS));
        $this->assertNull(LegalTexts::changedAt(LegalTexts::PRIVACY));
        $this->assertFalse(AuditLog::query()->where('action', 'legal.mode')->exists());
    }

    public function test_update_mode_sets_notice_without_gate(): void
    {
        $user = $this->user();
        $this->actingAs($this->admin());
        $this->put('/backend/einstellungen/rechtliches', $this->legalForm(['privacy_mode' => 'update']))->assertSessionHasNoErrors();

        $this->assertNotNull(LegalTexts::noticeAt(LegalTexts::PRIVACY));
        $this->assertNotNull(LegalTexts::changedAt(LegalTexts::PRIVACY));
        $this->assertSame(LegalTexts::DEFAULT_VERSION, LegalTexts::version(LegalTexts::PRIVACY));
        $this->assertTrue(AuditLog::query()->where('action', 'legal.mode')->exists());

        $this->actingAs($user);
        $this->get('/konto')->assertOk()->assertSee('Die Datenschutzerklärung wurde am 05.11.2026 aktualisiert.');
    }

    public function test_reaccept_mode_sets_version_and_no_notice(): void
    {
        $this->actingAs($this->admin());
        $this->put('/backend/einstellungen/rechtliches', $this->legalForm(['terms_mode' => 'reaccept']))->assertSessionHasNoErrors();

        $this->assertNotSame(LegalTexts::DEFAULT_VERSION, LegalTexts::version(LegalTexts::TERMS));
        $this->assertNull(LegalTexts::noticeAt(LegalTexts::TERMS));
        $this->assertNotNull(LegalTexts::changedAt(LegalTexts::TERMS));
    }

    public function test_auto_mode_update_when_text_changed_and_silent_otherwise(): void
    {
        $this->actingAs($this->admin());
        $this->put('/backend/einstellungen/rechtliches', $this->legalForm(['legal_name' => 'Anderer Name', 'legal_email' => 'x@example.org', 'legal_location' => 'Bern']))
            ->assertSessionHasNoErrors();
        $this->assertNull(LegalTexts::noticeAt(LegalTexts::PRIVACY));
        $this->assertNull(LegalTexts::noticeAt(LegalTexts::TERMS));
        $this->assertNull(LegalTexts::changedAt(LegalTexts::PRIVACY));

        $this->put('/backend/einstellungen/rechtliches', $this->legalForm(['terms_text' => "Titel\n\nGeänderter Text."]))->assertSessionHasNoErrors();
        $this->assertNotNull(LegalTexts::noticeAt(LegalTexts::TERMS));
        $this->assertNull(LegalTexts::noticeAt(LegalTexts::PRIVACY));
    }

    public function test_auto_mode_ignores_line_ending_difference_of_stored_text(): void
    {
        Setting::put(LegalTexts::textKey(LegalTexts::TERMS), "Titel\r\n\r\nText.\r\n");
        $this->actingAs($this->admin());
        $this->put('/backend/einstellungen/rechtliches', $this->legalForm(['terms_text' => "Titel\n\nText."]))->assertSessionHasNoErrors();
        $this->assertNull(LegalTexts::noticeAt(LegalTexts::TERMS));
    }

    public function test_banner_hidden_on_own_text_page_only(): void
    {
        Setting::put(AppSettings::TERMS_NOTICE, '2026-11-05 09:00:00');
        Setting::put(AppSettings::PRIVACY_NOTICE, '2026-11-05 09:00:00');
        $this->actingAs($this->user());

        $this->get('/datenschutz')->assertOk()
            ->assertDontSee('data-testid="legal-notice-privacy"', false)
            ->assertSee('data-testid="legal-notice-terms"', false);
        $this->get('/nutzungsbestimmungen')->assertOk()
            ->assertDontSee('data-testid="legal-notice-terms"', false)
            ->assertSee('data-testid="legal-notice-privacy"', false);
    }

    public function test_banner_shown_dismissed_and_not_on_consent_page(): void
    {
        $user = $this->user();
        Setting::put(AppSettings::TERMS_NOTICE, '2026-11-05 09:00:00');
        $this->actingAs($user);

        $this->get('/konto')->assertOk()
            ->assertSee('data-testid="legal-notice-terms"', false)
            ->assertSee('Die Nutzungsbestimmungen wurden am 05.11.2026 aktualisiert.')
            ->assertDontSee('data-testid="legal-notice-privacy"', false);

        $this->from('/konto')->post('/rechtliches/terms/gelesen')->assertRedirect('/konto');
        $this->assertNotNull($user->fresh()->terms_notice_seen_at);
        $this->get('/konto')->assertOk()->assertDontSee('data-testid="legal-notice-terms"', false);

        // A newer notice is shown again.
        Setting::put(AppSettings::TERMS_NOTICE, '2026-11-05 09:59:59');
        $user->forceFill(['terms_notice_seen_at' => '2026-11-05 09:00:00'])->save();
        $this->get('/konto')->assertSee('data-testid="legal-notice-terms"', false);

        $this->post('/rechtliches/foo/gelesen')->assertNotFound();

        $gated = User::factory()->withoutConsent()->create();
        $this->actingAs($gated)->get('/zustimmung')->assertOk()->assertDontSee('legal-notice-terms');
    }

    public function test_banner_absent_for_new_registration(): void
    {
        Mail::fake();
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_PASSWORD);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        Setting::put(AppSettings::TERMS_NOTICE, '2026-11-05 09:00:00');
        Setting::put(AppSettings::PRIVACY_NOTICE, '2026-11-05 09:00:00');

        Carbon::setTestNow('2026-11-05 10:30:00');
        $this->post('/registrieren', ['code' => 'durst', 'username' => 'neu.user', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'consent' => '1'])
            ->assertRedirect(route('account.dashboard'));
        $this->get('/konto')->assertOk()->assertDontSee('legal-notice-');
    }

    public function test_accepting_consent_marks_notices_seen(): void
    {
        Setting::put(AppSettings::TERMS_NOTICE, '2026-11-05 09:00:00');
        $user = User::factory()->withoutConsent()->create();
        $this->actingAs($user);
        $this->post('/zustimmung', [
            'consent' => '1',
            'privacy_version' => LegalTexts::version(LegalTexts::PRIVACY),
            'terms_version' => LegalTexts::version(LegalTexts::TERMS),
        ]);
        $this->get('/konto')->assertOk()->assertDontSee('legal-notice-');
    }

    public function test_price_change_triggers_terms_notice(): void
    {
        $this->actingAs($this->admin());
        $this->put('/backend/einstellungen', $this->settingsForm(['beer_price' => '2.00']))->assertSessionHasNoErrors();

        $this->assertNotNull(LegalTexts::noticeAt(LegalTexts::TERMS));
        $this->assertNotNull(LegalTexts::changedAt(LegalTexts::TERMS));
        $this->assertNull(LegalTexts::noticeAt(LegalTexts::PRIVACY));
        $this->actingAs($this->user())->get('/konto')->assertSee('Die Nutzungsbestimmungen wurden am 05.11.2026 aktualisiert.');
    }

    public function test_limit_and_undo_change_trigger_terms_notice(): void
    {
        $this->actingAs($this->admin());
        $this->put('/backend/einstellungen', $this->settingsForm(['default_limit' => '5.00']))->assertSessionHasNoErrors();
        $this->assertNotNull(LegalTexts::noticeAt(LegalTexts::TERMS));

        Setting::forget(AppSettings::TERMS_NOTICE);
        $this->put('/backend/einstellungen', $this->settingsForm(['default_limit' => '5.00', 'undo_minutes' => ['consume' => 20, 'round' => 10, 'guest' => 10, 'gift' => 5]]))->assertSessionHasNoErrors();
        $this->assertNotNull(LegalTexts::noticeAt(LegalTexts::TERMS));
    }

    public function test_unchanged_values_do_not_trigger_notice(): void
    {
        $this->actingAs($this->admin());
        $this->put('/backend/einstellungen', $this->settingsForm())->assertSessionHasNoErrors();

        $this->assertNull(LegalTexts::noticeAt(LegalTexts::TERMS));
        $this->assertNull(LegalTexts::changedAt(LegalTexts::TERMS));
    }

    public function test_public_page_shows_last_changed_only_when_different(): void
    {
        $this->get('/nutzungsbestimmungen')->assertOk()->assertDontSee('Zuletzt geändert');

        Setting::put(AppSettings::TERMS_CHANGED, '2026-11-05 09:00:00');
        $this->get('/nutzungsbestimmungen')->assertOk()
            ->assertSee('Stand: '.LegalTexts::versionDate(LegalTexts::DEFAULT_VERSION).' · Zuletzt geändert: 05.11.2026');

        Setting::put(AppSettings::TERMS_CHANGED, LegalTexts::DEFAULT_VERSION);
        $this->get('/nutzungsbestimmungen')->assertDontSee('Zuletzt geändert');
    }
}

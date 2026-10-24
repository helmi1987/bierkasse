<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LegalRevision;
use App\Models\Setting;
use App\Models\User;
use App\Services\LegalArchive;
use App\Support\AppSettings;
use App\Support\LegalTexts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class LegalRevisionTest extends TestCase
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

    private function revisions(string $kind): int
    {
        return LegalRevision::query()->where('kind', $kind)->count();
    }

    public function test_first_access_creates_initial_revisions_with_rendered_text(): void
    {
        $this->assertSame(0, LegalRevision::query()->count());
        $this->get('/nutzungsbestimmungen')->assertOk();
        $this->get('/datenschutz')->assertOk();

        $terms = LegalRevision::latestOf(LegalTexts::TERMS);
        $this->assertSame(LegalRevision::REASON_INITIAL, $terms->reason);
        $this->assertSame(LegalTexts::DEFAULT_VERSION, $terms->version);
        $this->assertStringContainsString('Preis: CHF 1.50 pro Bier', $terms->body);
        $this->assertStringNotContainsString('{bierpreis}', $terms->body);
        $this->assertSame(hash('sha256', $terms->body), $terms->hash);
        $this->assertNull($terms->created_by);
        $this->assertSame(1, $this->revisions(LegalTexts::PRIVACY));
        $this->assertTrue(AuditLog::query()->where('action', 'legal.revision')->where('subject_id', $terms->id)->exists());
        $entry = AuditLog::query()->where('action', 'legal.revision')->where('subject_id', $terms->id)->first();
        $this->assertStringNotContainsString('Preis: CHF', (string) $entry->new_values);
        $this->assertStringContainsString($terms->hash, (string) $entry->new_values);
    }

    public function test_revision_is_created_on_text_save_and_not_duplicated_when_unchanged(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        LegalArchive::currentAll();

        $this->put('/backend/einstellungen/rechtliches', $this->legalForm(['terms_text' => "Nutzungsbestimmungen\n\n1. Neu\nNeue Regeln, {bierpreis}."]))
            ->assertSessionHasNoErrors();
        $latest = LegalRevision::latestOf(LegalTexts::TERMS);
        $this->assertSame(2, $this->revisions(LegalTexts::TERMS));
        $this->assertSame(LegalRevision::REASON_UPDATE, $latest->reason);
        $this->assertSame($admin->id, $latest->created_by);
        $this->assertStringContainsString('Neue Regeln, CHF 1.50.', $latest->body);
        // Privacy unchanged: no new revision.
        $this->assertSame(1, $this->revisions(LegalTexts::PRIVACY));

        // Saving again without change and viewing the pages: no duplicates.
        $this->put('/backend/einstellungen/rechtliches', $this->legalForm(['terms_text' => "Nutzungsbestimmungen\n\n1. Neu\nNeue Regeln, {bierpreis}."]))
            ->assertSessionHasNoErrors();
        $this->get('/nutzungsbestimmungen')->assertOk()->assertSee('Neue Regeln, CHF 1.50.');
        $this->assertSame(2, $this->revisions(LegalTexts::TERMS));
        $this->assertSame(1, $this->revisions(LegalTexts::PRIVACY));
    }

    public function test_reaccept_with_same_text_stores_new_revision_with_new_version(): void
    {
        $this->actingAs($this->admin());
        LegalArchive::currentAll();
        $this->put('/backend/einstellungen/rechtliches', $this->legalForm(['privacy_mode' => 'reaccept']))->assertSessionHasNoErrors();

        $latest = LegalRevision::latestOf(LegalTexts::PRIVACY);
        $this->assertSame(2, $this->revisions(LegalTexts::PRIVACY));
        $this->assertSame(LegalRevision::REASON_REACCEPT, $latest->reason);
        $this->assertSame(LegalTexts::version(LegalTexts::PRIVACY), $latest->version);
        $this->assertNotSame(LegalTexts::DEFAULT_VERSION, $latest->version);
    }

    public function test_name_change_creates_silent_revisions(): void
    {
        $this->actingAs($this->admin());
        LegalArchive::currentAll();
        $this->put('/backend/einstellungen/rechtliches', $this->legalForm(['legal_name' => 'Hans Muster']))->assertSessionHasNoErrors();

        $privacy = LegalRevision::latestOf(LegalTexts::PRIVACY);
        $this->assertSame(LegalRevision::REASON_SILENT, $privacy->reason);
        $this->assertStringContainsString('Hans Muster', $privacy->body);
    }

    public function test_price_change_creates_terms_revision(): void
    {
        $this->actingAs($this->admin());
        LegalArchive::currentAll();
        $this->put('/backend/einstellungen', $this->settingsForm(['beer_price' => '2.00']))->assertSessionHasNoErrors();

        $terms = LegalRevision::latestOf(LegalTexts::TERMS);
        $this->assertSame(2, $this->revisions(LegalTexts::TERMS));
        $this->assertSame(LegalRevision::REASON_SETTINGS, $terms->reason);
        $this->assertStringContainsString('Preis: CHF 2.00 pro Bier', $terms->body);
        // The default privacy policy does not use the price.
        $this->assertSame(1, $this->revisions(LegalTexts::PRIVACY));

        // Unchanged price: nothing new.
        $this->put('/backend/einstellungen', $this->settingsForm(['beer_price' => '2.00']))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->revisions(LegalTexts::TERMS));
    }

    public function test_change_outside_the_backend_is_archived_lazily(): void
    {
        LegalArchive::currentAll();
        // Placeholder values or the stored text changed elsewhere (same path as a deploy of a new default text).
        Setting::put(AppSettings::LEGAL_LOCATION, 'Deutschland');
        $this->setDefaultLimit(500);

        $this->get('/datenschutz')->assertOk()->assertSee('Server-Standort: Deutschland');
        $privacy = LegalRevision::latestOf(LegalTexts::PRIVACY);
        $this->assertSame(LegalRevision::REASON_AUTO, $privacy->reason);
        $this->assertStringContainsString('Server-Standort: Deutschland', $privacy->body);

        $user = $this->user();
        $this->actingAs($user)->get('/konto')->assertOk();
        // The consent gate is not triggered by such a change (version unchanged) ...
        $this->assertFalse($user->fresh()->needsConsent());
        // ... but the terms page archives the new limit.
        $this->get('/nutzungsbestimmungen')->assertOk();
        $this->assertStringContainsString('CHF 5.00', LegalRevision::latestOf(LegalTexts::TERMS)->body);
        $this->assertSame(LegalRevision::REASON_AUTO, LegalRevision::latestOf(LegalTexts::TERMS)->reason);
    }

    public function test_registration_stores_revision_ids(): void
    {
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_PASSWORD);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');

        $this->post('/registrieren', [
            'code' => 'durst', 'username' => 'neu.user', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'consent' => '1',
        ])->assertRedirect(route('account.dashboard'));

        $user = User::query()->where('username', 'neu.user')->firstOrFail();
        $this->assertSame(LegalRevision::latestOf(LegalTexts::PRIVACY)->id, $user->privacy_revision_id);
        $this->assertSame(LegalRevision::latestOf(LegalTexts::TERMS)->id, $user->terms_revision_id);
    }

    public function test_consent_stores_displayed_revision_ids(): void
    {
        $user = User::factory()->withoutConsent()->create();
        $this->actingAs($user);
        $this->get('/zustimmung')->assertOk();
        $privacy = LegalRevision::latestOf(LegalTexts::PRIVACY);
        $terms = LegalRevision::latestOf(LegalTexts::TERMS);

        $this->post('/zustimmung', ['consent' => '1', 'privacy_revision' => $privacy->id, 'terms_revision' => $terms->id])->assertRedirect();
        $fresh = $user->fresh();
        $this->assertSame($privacy->id, $fresh->privacy_revision_id);
        $this->assertSame($terms->id, $fresh->terms_revision_id);
        $this->assertFalse($fresh->needsConsent());

        // Konto > Einstellungen links to the accepted revisions.
        $this->get('/konto/einstellungen')->assertOk()
            ->assertSee('data-testid="account-consent"', false)
            ->assertSee(route('legal.revision', $privacy))->assertSee(route('legal.revision', $terms));
    }

    public function test_consent_with_outdated_revision_id_is_shown_again(): void
    {
        $user = User::factory()->withoutConsent()->create();
        $this->actingAs($user);
        $this->get('/zustimmung')->assertOk();
        $shown = LegalRevision::latestOf(LegalTexts::TERMS)->id;

        // The price changes while the page is open: new revision, same "Stand".
        $this->setPrice(300);

        $this->post('/zustimmung', ['consent' => '1', 'privacy_revision' => LegalRevision::latestOf(LegalTexts::PRIVACY)->id, 'terms_revision' => $shown])
            ->assertRedirect(route('consent.show'))
            ->assertSessionHas('warning', __('legal.consent_changed'));
        $this->assertNull($user->fresh()->consent_accepted_at);
        $this->assertNotSame($shown, LegalRevision::latestOf(LegalTexts::TERMS)->id);

        // Missing ids (old form) are refused as well.
        $this->post('/zustimmung', ['consent' => '1'])->assertRedirect(route('consent.show'));
        $this->assertNull($user->fresh()->consent_accepted_at);
    }

    public function test_revision_view_requires_login(): void
    {
        $revision = LegalArchive::current(LegalTexts::TERMS);
        $this->get(route('legal.revision', $revision))->assertRedirect(route('login'));

        $this->actingAs($this->user());
        $this->get(route('legal.revision', $revision))->assertOk()
            ->assertSee('Fassung vom '.$revision->label().' (Stand 24.10.2026)')
            ->assertSee('Preis: CHF 1.50 pro Bier');
        $this->get('/rechtliches/fassung/99999')->assertNotFound();
    }

    public function test_archived_text_is_shown_as_stored_and_escaped(): void
    {
        $revision = LegalRevision::query()->create([
            'kind' => LegalTexts::TERMS, 'version' => LegalTexts::DEFAULT_VERSION,
            'body' => "Alt\n\n<b>x</b> {bierpreis}", 'hash' => hash('sha256', "Alt\n\n<b>x</b> {bierpreis}"), 'reason' => LegalRevision::REASON_AUTO,
        ]);
        $this->actingAs($this->user());
        // Placeholders are not replaced again (the stored text is what users saw).
        $this->get(route('legal.revision', $revision))->assertOk()
            ->assertSee('&lt;b&gt;x&lt;/b&gt; {bierpreis}', false);
    }

    public function test_backend_list_for_kassier_and_admin_only(): void
    {
        LegalArchive::currentAll();
        $this->actingAs($this->user());
        $this->get(route('backend.legal-revisions'))->assertForbidden();

        $this->actingAs($this->kassier());
        $this->get(route('backend.legal-revisions'))->assertOk()
            ->assertSee('data-testid="legal-revisions"', false)
            ->assertSee(route('legal.revision', LegalRevision::latestOf(LegalTexts::TERMS)))
            ->assertSee(__('legal.reason.initial'));
        $this->get(route('backend.legal-revisions', ['kind' => 'privacy']))->assertOk()
            ->assertDontSee(route('legal.revision', LegalRevision::latestOf(LegalTexts::TERMS)));

        $this->actingAs($this->admin());
        $this->get(route('backend.legal-revisions'))->assertOk();
    }

    public function test_backend_user_detail_links_accepted_revisions(): void
    {
        $revisions = LegalArchive::currentAll();
        $user = $this->user(LegalTexts::consentAttributes($revisions));
        $old = $this->user();

        $this->actingAs($this->kassier());
        $this->get(route('backend.users.show', $user))->assertOk()
            ->assertSee('data-testid="accepted-privacy"', false)
            ->assertSee(route('legal.revision', $revisions['terms']));
        // Consent before 1.19.0 (no revision id): "Stand" only.
        $this->get(route('backend.users.show', $old))->assertOk()
            ->assertDontSee('data-testid="accepted-privacy"', false)
            ->assertSee('Datenschutzerklärung (Stand 24.10.2026)');
    }

    public function test_revisions_are_immutable(): void
    {
        $revision = LegalArchive::current(LegalTexts::TERMS);

        // No route changes or deletes a revision.
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'fassung')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri());
            }
        }
        $this->actingAs($this->admin());
        $this->delete('/rechtliches/fassung/'.$revision->id)->assertStatus(405);
        $this->put('/rechtliches/fassung/'.$revision->id)->assertStatus(405);

        try {
            $revision->update(['body' => 'manipuliert']);
            $this->fail('Update must be refused.');
        } catch (LogicException) {
        }
        try {
            $revision->delete();
            $this->fail('Delete must be refused.');
        } catch (LogicException) {
        }
        $this->assertSame(hash('sha256', $revision->fresh()->body), $revision->fresh()->hash);
    }
}

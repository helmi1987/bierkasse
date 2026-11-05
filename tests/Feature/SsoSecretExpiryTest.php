<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\AuditLog;
use App\Models\SsoProvider;
use App\Services\SsoSecretReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SsoSecretExpiryTest extends TestCase
{
    use RefreshDatabase;

    private SsoProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));

        $this->provider = SsoProvider::query()->create([
            'name' => 'Firmen-SSO',
            'slug' => 'firma',
            'type' => 'oidc',
            'enabled' => true,
            'issuer' => 'https://sso.example/realms/office',
            'authorize_url' => 'https://sso.example/auth',
            'token_url' => 'https://sso.example/token',
            'client_id' => 'bier-client',
            'client_secret' => 'top-secret',
            'scopes' => 'openid profile email',
            'use_pkce' => true,
            'label_claim' => 'email',
        ]);
    }

    private function expiresInDays(int $days, ?int $stage = null): void
    {
        $this->provider->forceFill([
            'client_secret_expires_at' => today()->addDays($days),
            'secret_reminder_stage' => $stage,
        ])->save();
    }

    /** @return array<string, string> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'type' => 'oidc', 'name' => 'Firmen-SSO', 'slug' => 'firma', 'enabled' => '1', 'use_pkce' => '1',
            'scopes' => 'openid profile email', 'token_auth' => 'post',
            'subject_claim' => 'sub', 'email_claim' => 'email', 'label_claim' => 'email',
            'authorize_url' => 'https://sso.example/auth', 'token_url' => 'https://sso.example/token', 'client_id' => 'bier-client',
        ], $extra);
    }

    public function test_admin_saves_expiry_date_and_change_resets_stage(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/sso/'.$this->provider->id.'/edit')->assertOk()
            ->assertSee('id="client_secret_expires_at"', false)
            ->assertSee('Leer = keine Erinnerung.');

        $this->put('/admin/sso/'.$this->provider->id, $this->payload(['client_secret_expires_at' => '2027-06-30']))
            ->assertSessionHas('status');

        $this->provider->refresh();
        $this->assertSame('2027-06-30', $this->provider->client_secret_expires_at->format('Y-m-d'));
        $audit = AuditLog::query()->where('action', 'sso_provider.update')->latest('id')->firstOrFail();
        $this->assertStringContainsString('client_secret_expires_at', (string) $audit->new_values);
        $this->assertStringNotContainsString('secret_reminder_stage', (string) $audit->new_values);

        $this->get('/admin/sso')->assertOk()->assertSee('Secret bis 30.06.2027')->assertSee('data-testid="secret-expiry-firma"', false);

        $this->provider->forceFill(['secret_reminder_stage' => 30])->save();
        $this->put('/admin/sso/'.$this->provider->id, $this->payload(['client_secret_expires_at' => '2028-06-30']))
            ->assertSessionHas('status');
        $this->assertNull($this->provider->refresh()->secret_reminder_stage);

        // Unchanged date keeps the stage; clearing it resets the stage.
        $this->provider->forceFill(['secret_reminder_stage' => 30])->save();
        $this->put('/admin/sso/'.$this->provider->id, $this->payload(['client_secret_expires_at' => '2028-06-30']));
        $this->assertSame(30, $this->provider->refresh()->secret_reminder_stage);
        $this->put('/admin/sso/'.$this->provider->id, $this->payload(['client_secret_expires_at' => '']));
        $this->provider->refresh();
        $this->assertNull($this->provider->client_secret_expires_at);
        $this->assertNull($this->provider->secret_reminder_stage);

        $this->put('/admin/sso/'.$this->provider->id, $this->payload(['client_secret_expires_at' => '30.06.2028']))
            ->assertSessionHasErrors('client_secret_expires_at');
    }

    public function test_microsoft_form_shows_hint_and_apple_has_no_field(): void
    {
        $this->actingAs($this->admin())->get('/admin/sso/create?type=microsoft')->assertOk()
            ->assertSee('Client-Secrets gelten höchstens 24 Monate');
        $this->get('/admin/sso/create?type=apple')->assertOk()
            ->assertDontSee('id="client_secret_expires_at"', false);
    }

    public function test_banner_stages_for_admin(): void
    {
        $admin = $this->admin();

        $this->expiresInDays(120);
        $this->actingAs($admin)->get('/konto')->assertOk()->assertDontSee('secret-warning-firma');

        $this->expiresInDays(60);
        $this->get('/konto')->assertOk()
            ->assertSee('class="alert info" role="alert" data-testid="secret-warning-firma"', false)
            ->assertSee('läuft am '.today()->addDays(60)->format('d.m.Y').' ab (noch 60 Tage)')
            ->assertSee(route('backend.sso.edit', $this->provider));

        $this->expiresInDays(20);
        $this->get('/konto')->assertSee('class="alert warn" role="alert" data-testid="secret-warning-firma"', false);

        $this->expiresInDays(3);
        $this->get('/konto')->assertSee('class="alert bad" role="alert" data-testid="secret-warning-firma"', false);

        $this->expiresInDays(-1);
        $this->get('/konto')->assertSee('class="alert bad" role="alert" data-testid="secret-warning-firma"', false)
            ->assertSee('abgelaufen');
        $this->get('/admin/sso')->assertSee('Secret abgelaufen am '.today()->subDay()->format('d.m.Y'))
            ->assertSee('class="pill bad"', false);
    }

    public function test_banner_not_shown_to_non_admin(): void
    {
        $this->expiresInDays(3);
        $this->actingAs($this->user())->get('/konto')->assertOk()->assertDontSee('secret-warning-firma');
        $this->actingAs($this->kassier())->get('/konto')->assertOk()->assertDontSee('secret-warning-firma');
    }

    public function test_reminder_stages_are_mailed_once(): void
    {
        Mail::fake();
        $this->admin(['email' => 'chef@example.org']);
        $this->user(['email' => 'gast@example.org']);
        $expiry = today()->addDays(95);
        $this->provider->forceFill(['client_secret_expires_at' => $expiry])->save();

        $this->assertSame([], SsoSecretReminders::run());
        Mail::assertNothingSent();

        $this->travelTo($expiry->copy()->subDays(90)->setTime(8, 0));
        $this->assertSame([['slug' => 'firma', 'stage' => 90, 'days_left' => 90, 'recipients' => 1]], SsoSecretReminders::run());
        Mail::assertSentCount(1);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->hasTo('chef@example.org')
            && str_contains($m->noticeSubject, 'läuft in 90 Tagen ab'));
        $this->assertSame(90, $this->provider->refresh()->secret_reminder_stage);
        $audit = AuditLog::query()->where('action', 'sso_provider.secret_reminder')->firstOrFail();
        $this->assertSame('cli', $audit->actor_name);

        SsoSecretReminders::run();
        Mail::assertSentCount(1);

        $this->travelTo($expiry->copy()->subDays(45)->setTime(8, 0));
        SsoSecretReminders::run();
        SsoSecretReminders::run();
        Mail::assertSentCount(2);
        $this->assertSame(60, $this->provider->refresh()->secret_reminder_stage);

        $this->travelTo($expiry->copy()->subDays(5)->setTime(8, 0));
        SsoSecretReminders::run();
        Mail::assertSentCount(3);
        $this->assertSame(7, $this->provider->refresh()->secret_reminder_stage);

        $this->travelTo($expiry->copy()->setTime(8, 0));
        SsoSecretReminders::run();
        Mail::assertSentCount(4);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => str_contains($m->noticeSubject, 'heute'));
        $this->assertSame(0, $this->provider->refresh()->secret_reminder_stage);

        $this->travelTo($expiry->copy()->addDays(3)->setTime(8, 0));
        SsoSecretReminders::run();
        Mail::assertSentCount(4);
        $this->assertSame(4, AuditLog::query()->where('action', 'sso_provider.secret_reminder')->count());

        // New date (stage reset) starts the reminders again.
        $this->provider->forceFill(['client_secret_expires_at' => today()->addDays(10), 'secret_reminder_stage' => null])->save();
        SsoSecretReminders::run();
        Mail::assertSentCount(5);
        $this->assertSame(14, $this->provider->refresh()->secret_reminder_stage);
    }

    public function test_expired_secret_without_reminder_gets_expired_mail(): void
    {
        Mail::fake();
        $this->admin(['email' => 'chef@example.org']);
        $this->expiresInDays(-2);

        SsoSecretReminders::run();
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => str_contains($m->noticeSubject, 'Client-Secret abgelaufen'));
        $this->assertSame(0, $this->provider->refresh()->secret_reminder_stage);
    }

    public function test_microsoft_mail_mentions_entra(): void
    {
        Mail::fake();
        $this->admin(['email' => 'chef@example.org']);
        $this->provider->forceFill(['type' => 'microsoft', 'client_secret_expires_at' => today()->addDays(30)])->save();

        SsoSecretReminders::run();
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => str_contains(implode(' ', $m->lines), 'Entra Admin Center')
            && $m->url === route('backend.sso.edit', $this->provider));
    }

    public function test_no_recipient_does_not_mark_stage(): void
    {
        Mail::fake();
        $this->admin(['email' => null]);
        $this->expiresInDays(7);

        SsoSecretReminders::run();
        Mail::assertNothingSent();
        $this->assertNull($this->provider->refresh()->secret_reminder_stage);

        $this->admin(['email' => 'chef@example.org']);
        SsoSecretReminders::run();
        Mail::assertSentCount(1);
        $this->assertSame(7, $this->provider->refresh()->secret_reminder_stage);
    }

    public function test_personal_provider_is_ignored(): void
    {
        Mail::fake();
        $admin = $this->admin(['email' => 'chef@example.org']);
        $owner = $this->user();
        $this->provider->forceFill(['client_secret_expires_at' => null])->save();
        $personal = SsoProvider::query()->create([
            'name' => 'Eigener', 'slug' => 'p-eigener', 'type' => 'oidc', 'enabled' => true,
            'client_id' => 'x', 'client_secret' => 'y', 'scopes' => 'openid',
        ]);
        $personal->forceFill(['owner_user_id' => $owner->id, 'client_secret_expires_at' => today()->addDays(5)])->save();

        $this->assertSame([], SsoSecretReminders::run());
        Mail::assertNothingSent();
        $this->actingAs($admin)->get('/konto')->assertOk()->assertDontSee('secret-warning-p-eigener');
    }

    public function test_command_and_dry_run(): void
    {
        Mail::fake();
        $this->admin(['email' => 'chef@example.org']);
        $this->expiresInDays(14);

        $this->artisan('sso:secret-reminders --dry-run')->expectsOutputToContain('firma: stage 14')->assertExitCode(0);
        Mail::assertNothingSent();
        $this->assertNull($this->provider->refresh()->secret_reminder_stage);

        $this->artisan('sso:secret-reminders')->expectsOutputToContain('firma: stage 14')->assertExitCode(0);
        Mail::assertSentCount(1);
        $this->assertSame(14, $this->provider->refresh()->secret_reminder_stage);
    }

    public function test_scheduler_lists_command(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('sso:secret-reminders')->assertExitCode(0);
    }
}

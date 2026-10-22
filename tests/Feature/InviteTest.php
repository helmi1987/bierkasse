<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\Invitation;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Qr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InviteTest extends TestCase
{
    use RefreshDatabase;

    private function mode(string $mode, ?string $password = 'durst'): void
    {
        Setting::put(AppSettings::REGISTRATION_MODE, $mode);
        if ($password !== null) {
            Setting::put(AppSettings::REGISTRATION_PASSWORD, $password);
        }
    }

    /**
     * Creates an invitation via the invite page and returns the plain token from the shown link.
     */
    private function createInvitation(User $user): string
    {
        $this->actingAs($user);
        $response = $this->post('/konto/einladen', $this->tokened('invite'))->assertOk();
        preg_match('/einladung=([A-Za-z0-9]+)/', $response->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null);
        $this->app['auth']->guard()->logout();
        $this->flushSession();

        return $m[1];
    }

    private function data(array $overrides = []): array
    {
        return array_merge([
            'username' => 'neu.user',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ], $overrides);
    }

    // --- Invite page -------------------------------------------------------------------

    public function test_user_creates_invitation_link_shown_once(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $user = $this->user();
        $this->actingAs($user);

        $this->get('/konto')->assertOk()->assertSee(route('account.invite'))->assertSee('Jemand Neues einladen');
        $this->get('/konto/einladen')->assertOk()->assertSee('Neuen Einladungslink erstellen')
            ->assertDontSee('code=durst')->assertDontSee('einladung=');

        $response = $this->post('/konto/einladen', $this->tokened('invite'))->assertOk()
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertSee(route('register').'?einladung=', false)
            ->assertSee('data-share="#invite-url"', false)
            ->assertSee('data-copy="#invite-url"', false)
            ->assertSee('nur für 1 Person')
            ->assertDontSee('code=durst');

        preg_match('/einladung=([A-Za-z0-9]+)/', $response->getContent(), $m);
        $token = $m[1];
        $this->assertGreaterThanOrEqual(32, strlen($token));

        $invitation = Invitation::query()->sole();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertSame($user->id, $invitation->created_by);
        $this->assertTrue($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        // Nothing plain stored.
        $this->assertStringNotContainsString($token, (string) json_encode(Invitation::query()->get()->toArray()));

        // Not shown again later.
        $this->get('/konto/einladen')->assertOk()->assertDontSee($token)->assertSee('Offen');
    }

    public function test_password_mode_offers_only_invitation_links(): void
    {
        $this->mode(AppSettings::MODE_PASSWORD);
        $this->actingAs($this->user());

        $this->get('/konto/einladen')->assertOk()
            ->assertSee('Neuen Einladungslink erstellen')
            ->assertDontSee('code=durst')->assertDontSee('flyer=')
            ->assertDontSee('Registrierungspasswort');
        $this->post('/konto/einladen', $this->tokened('invite'))->assertOk()
            ->assertSee(route('register').'?einladung=', false)
            ->assertDontSee('code=durst')->assertDontSee('flyer=');
    }

    public function test_invite_mode_never_shows_password_qr(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $this->actingAs($this->user());

        $this->get('/konto/einladen')->assertOk()->assertDontSee('code=durst');
        $this->post('/konto/einladen', $this->tokened('invite'))->assertOk()->assertDontSee('code=durst');
    }

    public function test_closed_mode_hides_invite(): void
    {
        $this->mode(AppSettings::MODE_CLOSED);
        $this->actingAs($this->user());

        $this->get('/konto/einladen')->assertNotFound();
        $this->post('/konto/einladen', $this->tokened('invite'))->assertNotFound();
        $this->get('/konto')->assertOk()->assertDontSee(route('account.invite'));
        $this->assertSame(0, Invitation::query()->count());
    }

    public function test_guests_cannot_see_invite(): void
    {
        $this->mode(AppSettings::MODE_INVITE);

        $this->get('/konto/einladen')->assertRedirect(route('login'));
    }

    public function test_double_submit_creates_one_invitation(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $this->actingAs($this->user());
        $data = $this->tokened('invite');

        $this->post('/konto/einladen', $data)->assertOk();
        $this->post('/konto/einladen', $data)->assertRedirect(route('account.invite'));
        $this->assertSame(1, Invitation::query()->count());
    }

    public function test_max_ten_open_invitations_per_user(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $user = $this->user();
        for ($i = 0; $i < Invitation::MAX_OPEN_PER_USER; $i++) {
            Invitation::issue($user);
        }
        // Used and expired ones do not count.
        [$used] = Invitation::issue($user);
        $used->forceFill(['used_at' => now(), 'used_by' => $this->user()->id])->save();
        [$expired] = Invitation::issue($user);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->actingAs($user);

        $this->post('/konto/einladen', $this->tokened('invite'))->assertRedirect(route('account.invite'))->assertSessionHas('error');
        $this->assertSame(12, Invitation::query()->count());
        $this->get('/konto/einladen')->assertSee('10 offene Einladungslinks');

        // Another user is not affected.
        $this->actingAs($this->user());
        $this->post('/konto/einladen', $this->tokened('invite'))->assertOk();
        $this->assertSame(13, Invitation::query()->count());
    }

    public function test_user_revokes_own_unused_invitation(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $user = $this->user();
        [$invitation, $token] = Invitation::issue($user);
        $this->actingAs($user);

        $this->delete('/konto/einladungen/'.$invitation->id)->assertRedirect()->assertSessionHas('status');
        $this->assertSame(0, Invitation::query()->count());

        $this->app['auth']->guard()->logout();
        $this->get('/registrieren?einladung='.$token)->assertOk()->assertSee('ungültig');
    }

    public function test_user_cannot_revoke_foreign_or_used_invitation(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        [$foreign] = Invitation::issue($this->user());
        $user = $this->user();
        [$used] = Invitation::issue($user);
        $used->forceFill(['used_at' => now(), 'used_by' => $this->user()->id])->save();
        $this->actingAs($user);

        $this->delete('/konto/einladungen/'.$foreign->id)->assertForbidden();
        $this->delete('/konto/einladungen/'.$used->id)->assertSessionHas('error');
        $this->assertSame(2, Invitation::query()->count());
    }

    // --- Registration with invitation ----------------------------------------------------

    public function test_invite_mode_token_works_once_and_password_is_rejected(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $inviter = $this->user(['username' => 'gastgeber']);
        $token = $this->createInvitation($inviter);

        // Password not accepted in mode "invite".
        $this->get('/registrieren?code=durst')->assertOk()->assertDontSee('name="username"', false);
        $this->post('/registrieren', $this->data(['code' => 'durst']))->assertSessionHasErrors('code');
        $this->assertNull(User::query()->where('username', 'neu.user')->first());

        // Valid link: form without password field, inviter shown.
        $this->get('/registrieren?einladung='.$token)->assertOk()
            ->assertSee('Eingeladen von gastgeber')
            ->assertSee('name="einladung" value="'.$token.'"', false)
            ->assertDontSee('name="code"', false);

        $this->post('/registrieren', $this->data(['einladung' => $token]))->assertRedirect(route('account.dashboard'));
        $user = User::query()->where('username', 'neu.user')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $invitation = Invitation::query()->sole();
        $this->assertSame($user->id, $invitation->used_by);
        $this->assertNotNull($invitation->used_at);
        $this->assertSame($inviter->id, $user->invitation->created_by);

        // Second use impossible.
        $this->app['auth']->guard()->logout();
        $this->get('/registrieren?einladung='.$token)->assertOk()->assertSee('schon benutzt')->assertDontSee('name="username"', false);
        $this->post('/registrieren', $this->data(['einladung' => $token, 'username' => 'zweiter']))->assertSessionHasErrors('code');
        $this->assertNull(User::query()->where('username', 'zweiter')->first());
    }

    public function test_consume_is_atomic(): void
    {
        $user = $this->user();
        [$invitation] = Invitation::issue($user);
        $copy = Invitation::query()->findOrFail($invitation->id);

        $this->assertTrue($invitation->consume($this->user()));
        // A parallel request that loaded the invitation before cannot use it again.
        $this->assertFalse($copy->consume($this->user()));
    }

    public function test_password_mode_accepts_password_and_invitation(): void
    {
        $this->mode(AppSettings::MODE_PASSWORD);
        $token = $this->createInvitation($this->user());

        $this->post('/registrieren', $this->data(['code' => 'durst', 'username' => 'mit.passwort']))->assertRedirect(route('account.dashboard'));
        $this->app['auth']->guard()->logout();
        $this->post('/registrieren', $this->data(['einladung' => $token, 'username' => 'mit.einladung']))->assertRedirect(route('account.dashboard'));
        $this->app['auth']->guard()->logout();

        $this->assertNotNull(User::query()->where('username', 'mit.passwort')->first());
        $this->assertNotNull(User::query()->where('username', 'mit.einladung')->first());
        $this->assertSame(1, Invitation::query()->whereNotNull('used_at')->count());

        // Used link in mode "password": normal form with password field and a hint.
        $this->get('/registrieren?einladung='.$token)->assertOk()
            ->assertSee('name="code"', false)->assertSee('Registrierungspasswort registrieren');
    }

    public function test_closed_mode_rejects_invitation(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $token = $this->createInvitation($this->user());
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_CLOSED);

        $this->get('/registrieren?einladung='.$token)->assertOk()->assertSee('geschlossen')->assertDontSee('name="username"', false);
        $this->post('/registrieren', $this->data(['einladung' => $token]))->assertForbidden();
        $this->assertNull(User::query()->where('username', 'neu.user')->first());
        $this->assertNull(Invitation::query()->sole()->used_at);
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $token = $this->createInvitation($this->user());

        $this->travel(Invitation::VALID_DAYS)->days();
        $this->travel(1)->minutes();

        $this->get('/registrieren?einladung='.$token)->assertOk()->assertSee('abgelaufen')->assertDontSee('name="username"', false);
        $this->post('/registrieren', $this->data(['einladung' => $token]))->assertSessionHasErrors('code');
        $this->assertNull(User::query()->where('username', 'neu.user')->first());
    }

    public function test_invitation_of_inactive_creator_is_rejected(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $creator = $this->user();
        [, $token] = Invitation::issue($creator);
        $creator->forceFill(['status' => User::STATUS_LEFT])->save();

        $this->post('/registrieren', $this->data(['einladung' => $token]))->assertSessionHasErrors('code');
        $this->assertNull(User::query()->where('username', 'neu.user')->first());
    }

    public function test_wrong_tokens_are_rate_limited(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        [, $token] = Invitation::issue($this->user());

        for ($i = 0; $i < 5; $i++) {
            $this->get('/registrieren?einladung=falsch'.$i)->assertOk();
            $this->post('/registrieren', $this->data(['einladung' => 'falsch'.$i]))->assertSessionHasErrors('code');
        }
        // Even the valid token is not checked any more.
        $this->get('/registrieren?einladung='.$token)->assertOk()->assertSee('Zu viele')->assertDontSee('name="username"', false);
        $this->post('/registrieren', $this->data(['einladung' => $token]))->assertSessionHasErrors('code');
        $this->assertNull(User::query()->where('username', 'neu.user')->first());
        $this->assertNull(Invitation::query()->sole()->used_at);
    }

    public function test_registration_mail_mentions_inviter(): void
    {
        Mail::fake();
        $this->mode(AppSettings::MODE_INVITE);
        $this->kassier(['email' => 'kassier@example.org']);
        $token = $this->createInvitation($this->user(['username' => 'gastgeber']));

        $this->post('/registrieren', $this->data(['einladung' => $token]))->assertRedirect(route('account.dashboard'));

        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => ($m->rows['Eingeladen von'] ?? null) === 'gastgeber' && str_contains($m->render(), 'gastgeber'));
    }

    // --- Backend ----------------------------------------------------------------------

    public function test_backend_lists_and_revokes_invitations(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $creator = $this->user(['username' => 'ersteller']);
        [$open] = Invitation::issue($creator);
        [$used] = Invitation::issue($creator);
        $used->forceFill(['used_at' => now(), 'used_by' => $this->user(['username' => 'neuling'])->id])->save();
        [$expired] = Invitation::issue($creator);
        $expired->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($this->user());
        $this->get('/backend/einladungen')->assertForbidden();

        $this->actingAs($this->kassier());
        $this->get('/backend/einladungen')->assertOk()
            ->assertSee('Erstellt von ersteller')
            ->assertSee('Benutzt von neuling')
            ->assertSee('Abgelaufen')
            ->assertSee('Offen');

        $this->delete('/backend/einladungen/'.$used->id)->assertSessionHas('error');
        $this->delete('/backend/einladungen/'.$open->id)->assertSessionHas('status');
        $this->assertNull(Invitation::query()->find($open->id));
        $this->assertNotNull(Invitation::query()->find($used->id));
    }

    public function test_backend_registration_qr_in_invite_mode(): void
    {
        $this->mode(AppSettings::MODE_INVITE);
        $this->actingAs($this->admin());

        // Without a registration code there is nothing to show (no QR without a code).
        $this->get('/backend/registrierung-qr')->assertOk()
            ->assertSee('Kein aktiver Registrierungscode')
            ->assertDontSee('code=durst')
            ->assertDontSee('Flyer-Code neu erzeugen')
            ->assertDontSee(Qr::dataUri(route('register'), 480), false);
        $this->get('/backend/registrierung-qr?view=flyer')->assertOk()->assertDontSee('class="flyer-image"', false);
    }

    // --- Settings ----------------------------------------------------------------------

    public function test_admin_sets_registration_mode(): void
    {
        $this->actingAs($this->admin());
        $this->get('/backend/einstellungen')->assertOk()
            ->assertSee('name="registration_mode" value="invite" checked', false)
            ->assertSee('Offen mit Registrierungspasswort');

        $this->put('/backend/einstellungen', [
            'beer_price' => '1.50', 'default_limit' => '0', 'mail_transport' => 'sendmail', 'registration_mode' => 'closed',
        ])->assertSessionHas('status');
        Setting::flushCache();
        $this->assertSame(AppSettings::MODE_CLOSED, AppSettings::registrationMode());

        $this->put('/backend/einstellungen', [
            'beer_price' => '1.50', 'default_limit' => '0', 'mail_transport' => 'sendmail', 'registration_mode' => 'offen',
        ])->assertSessionHasErrors('registration_mode');
    }

    /**
     * @return array<string, array{0: ?bool, 1: string}>
     */
    public static function legacySwitchProvider(): array
    {
        return [
            'off' => [false, AppSettings::MODE_CLOSED],
            'on' => [true, AppSettings::MODE_PASSWORD],
            'unset (old default on)' => [null, AppSettings::MODE_PASSWORD],
        ];
    }

    #[DataProvider('legacySwitchProvider')]
    public function test_migration_converts_old_switch(?bool $enabled, string $expected): void
    {
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        if ($enabled !== null) {
            Setting::put(AppSettings::LEGACY_REGISTRATION_ENABLED, $enabled);
        }

        $migration = require database_path('migrations/2026_10_21_000200_migrate_registration_switch_to_mode.php');
        $migration->up();

        $this->assertSame($expected, AppSettings::registrationMode());
        $this->assertFalse(Setting::query()->whereKey(AppSettings::LEGACY_REGISTRATION_ENABLED)->exists());

        // Idempotent: an existing mode is never overwritten.
        Setting::put(AppSettings::REGISTRATION_MODE, AppSettings::MODE_INVITE);
        $migration->up();
        $this->assertSame(AppSettings::MODE_INVITE, AppSettings::registrationMode());
    }

    public function test_migration_keeps_default_on_fresh_install(): void
    {
        $migration = require database_path('migrations/2026_10_21_000200_migrate_registration_switch_to_mode.php');
        $migration->up();

        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(AppSettings::MODE_INVITE, AppSettings::registrationMode());
    }
}

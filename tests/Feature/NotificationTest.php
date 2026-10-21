<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_kassiers_get_mail_on_deposit_and_payout(): void
    {
        Mail::fake();
        $this->kassier(['email' => 'kassier@example.org']);
        $this->admin(['email' => 'admin@example.org']);
        $this->kassier(['email' => null]);
        $this->user(['email' => 'user@example.org']);
        $user = $this->user();

        $this->actingAs($user)->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => '10', 'method' => 'cash']));
        Mail::assertSent(KassierNotice::class, 2);
        Mail::assertSent(KassierNotice::class, fn ($m) => $m->hasTo('kassier@example.org'));
        Mail::assertNotSent(KassierNotice::class, fn ($m) => $m->hasTo('user@example.org'));

        Ledger::bookDeposit($user, 500, 'cash', User::query()->where('role', 'kassier')->first());
        Ledger::confirmDeposit(Transaction::query()->pending()->first(), User::query()->where('role', 'admin')->first());
        Ledger::requestPayout($user);
        Mail::assertSent(KassierNotice::class, 4);
    }

    public function test_no_mail_when_disabled(): void
    {
        Mail::fake();
        Setting::put(AppSettings::NOTIFY_KASSIER, false);
        $this->kassier(['email' => 'kassier@example.org']);

        Ledger::reportDeposit($this->user(), 1000, 'cash');
        Mail::assertNothingSent();
    }

    public function test_kassiers_and_admins_get_mail_on_registration(): void
    {
        Mail::fake();
        Setting::put(AppSettings::REGISTRATION_ENABLED, true);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        $this->kassier(['email' => 'kassier@example.org']);
        $this->admin(['email' => 'admin@example.org']);
        $this->user(['email' => 'user@example.org']);

        $this->post('/registrieren', [
            'code' => 'durst',
            'username' => 'neu.user',
            'first_name' => 'Neu',
            'last_name' => 'Ling',
            'email' => 'neu@example.org',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect(route('account.dashboard'));

        $user = User::query()->where('username', 'neu.user')->firstOrFail();
        Mail::assertSent(KassierNotice::class, 2);
        Mail::assertSent(KassierNotice::class, fn ($m) => $m->hasTo('admin@example.org'));
        Mail::assertNotSent(KassierNotice::class, fn ($m) => $m->hasTo('user@example.org'));
        Mail::assertSent(KassierNotice::class, function (KassierNotice $m) use ($user) {
            $text = $m->render();

            return str_contains($m->envelope()->subject, 'Neue Registrierung: neu.user')
                && str_contains($text, 'Benutzername: neu.user')
                && str_contains($text, 'Name: Neu Ling')
                && str_contains($text, 'E-Mail: neu@example.org')
                && str_contains($text, local_time($user->created_at))
                && str_contains($text, route('backend.users.show', $user));
        });
    }

    public function test_registration_mail_respects_switch_and_skips_missing_email(): void
    {
        Mail::fake();
        Setting::put(AppSettings::REGISTRATION_ENABLED, true);
        Setting::put(AppSettings::REGISTRATION_PASSWORD, 'durst');
        $this->kassier(['email' => 'kassier@example.org']);
        $data = [
            'code' => 'durst',
            'username' => 'ohne.mail',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ];

        $this->post('/registrieren', $data)->assertRedirect(route('account.dashboard'));
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => ! str_contains($m->render(), 'E-Mail:'));

        auth()->logout();
        Setting::put(AppSettings::NOTIFY_KASSIER, false);
        $this->post('/registrieren', ['username' => 'zweiter'] + $data)->assertRedirect(route('account.dashboard'));
        Mail::assertSent(KassierNotice::class, 1);
    }

    public function test_password_reset_by_mail(): void
    {
        Notification::fake();
        $user = $this->user(['username' => 'hans', 'email' => 'hans@example.org']);
        $noMail = $this->user(['username' => 'ohne', 'email' => null]);

        $this->post('/passwort-vergessen', ['login' => 'hans'])->assertSessionHas('status');
        $this->post('/passwort-vergessen', ['login' => 'ohne'])->assertSessionHas('status');
        $this->post('/passwort-vergessen', ['login' => 'niemand'])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        Notification::assertNotSentTo($noMail, ResetPassword::class);

        $this->get('/passwort-zuruecksetzen/'.$token.'?email=hans@example.org')->assertOk();
        $this->post('/passwort-zuruecksetzen', [
            'token' => $token,
            'email' => 'hans@example.org',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect(route('login'));

        $this->post('/anmelden', ['username' => 'hans', 'password' => 'brand-new-pass'])->assertRedirect(route('account.dashboard'));
    }

    public function test_reset_mail_is_german_and_uses_current_host(): void
    {
        $user = $this->user(['email' => 'hans@example.org']);
        $mail = (new ResetPassword('tok'))->toMail($user);

        $this->assertStringContainsString('Passwort zurücksetzen', $mail->subject);
        $this->assertStringStartsWith(url('/passwort-zuruecksetzen/tok'), $mail->actionUrl);
    }

    public function test_reset_request_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/passwort-vergessen', ['login' => 'x'.$i])->assertStatus(302);
        }
        $this->post('/passwort-vergessen', ['login' => 'x'])->assertStatus(429);
    }

    public function test_email_must_be_unique(): void
    {
        $this->user(['email' => 'same@example.org']);
        $user = $this->user();

        $this->actingAs($user)->put('/konto/profil', ['email' => 'same@example.org'])->assertSessionHasErrors('email');
        $this->put('/konto/profil', ['first_name' => 'Neu', 'email' => 'mine@example.org'])->assertSessionHas('status');
        $this->assertSame('mine@example.org', $user->fresh()->email);
        Password::broker();
    }
}

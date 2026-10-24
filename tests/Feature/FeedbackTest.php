<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\Feedback;
use App\Models\Setting;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\SystemInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function submit(User $user, array $data = []): TestResponse
    {
        $this->actingAs($user);

        return $this->post('/konto/feedback', $this->tokened('feedback', $data + [
            'type' => 'idea',
            'message' => "Mehr Bier bitte.\nUnd kälter.",
        ]));
    }

    // --- Submitting --------------------------------------------------------------------

    public function test_links_on_dashboard_and_settings_and_form_shows_three_types(): void
    {
        $this->actingAs($this->user());

        $this->get('/konto')->assertOk()->assertSee(route('account.feedback'));
        $this->get('/konto/einstellungen')->assertOk()->assertSee(route('account.feedback'));
        $this->get('/konto/feedback')->assertOk()
            ->assertSee('value="feedback" checked', false)
            ->assertSee('value="idea"', false)
            ->assertSee('value="bug"', false)
            ->assertSee('Antwort per Mail erwünscht');
    }

    public function test_reply_checkbox_hidden_without_email(): void
    {
        $this->actingAs($this->user(['email' => null]));

        $this->get('/konto/feedback')->assertOk()->assertDontSee('name="wants_reply"', false);
    }

    public function test_submit_stores_and_mails_all_active_admins(): void
    {
        Mail::fake();
        Setting::put(AppSettings::NOTIFY_KASSIER, false); // feedback mails are independent of this switch
        $admin1 = $this->admin(['email' => 'a1@example.org']);
        $admin2 = $this->admin(['email' => 'a2@example.org']);
        $this->admin(['email' => null]);
        $this->admin(['email' => 'left@example.org', 'status' => User::STATUS_LEFT]);
        $this->kassier(['email' => 'k@example.org']);
        $user = $this->user(['username' => 'hans', 'first_name' => 'Hans', 'last_name' => 'Muster', 'email' => 'hans@example.org']);

        $this->submit($user)->assertRedirect(route('account.feedback'))->assertSessionHas('status', 'Danke für dein Feedback!');

        $feedback = Feedback::query()->sole();
        $this->assertSame($user->id, $feedback->user_id);
        $this->assertSame('idea', $feedback->type);
        $this->assertSame("Mehr Bier bitte.\nUnd kälter.", $feedback->message);
        $this->assertFalse($feedback->wants_reply);
        $this->assertNull($feedback->read_at);

        Mail::assertSent(KassierNotice::class, 2);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->hasTo($admin1->email));
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->hasTo($admin2->email));
        Mail::assertSent(KassierNotice::class, function (KassierNotice $m) {
            $html = $m->render();

            return $m->title === 'Neues Feedback'
                && ! $m->hasReplyTo('hans@example.org')
                && $m->rows['Art'] === 'Idee'
                && $m->rows['Von'] === 'hans (Hans Muster)'
                && $m->rows['E-Mail'] === 'hans@example.org'
                && $m->rows['Antwort erwünscht'] === 'Nein'
                && str_contains($html, 'Mehr Bier bitte.<br>')
                && str_contains($html, 'Im Admin-Bereich ansehen')
                && str_contains($html, route('backend.feedback'));
        });
    }

    public function test_message_is_escaped_in_mail(): void
    {
        Mail::fake();
        $this->admin(['email' => 'a@example.org']);

        $this->submit($this->user(), ['message' => '<script>alert(1)</script>'])->assertRedirect();

        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => str_contains($m->render(), '&lt;script&gt;')
            && ! str_contains($m->render(), '<script>alert'));
    }

    public function test_reply_to_is_user_email_when_wanted(): void
    {
        Mail::fake();
        $this->admin(['email' => 'a@example.org']);
        $user = $this->user(['email' => 'hans@example.org']);

        $this->submit($user, ['wants_reply' => '1', 'type' => 'bug'])->assertRedirect();

        $this->assertTrue(Feedback::query()->sole()->wants_reply);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->hasReplyTo('hans@example.org')
            && $m->rows['Antwort erwünscht'] === 'Ja' && $m->rows['Art'] === 'Bug');
    }

    public function test_wants_reply_ignored_without_email(): void
    {
        Mail::fake();
        $this->admin(['email' => 'a@example.org']);

        $this->submit($this->user(['email' => null]), ['wants_reply' => '1'])->assertRedirect();

        $this->assertFalse(Feedback::query()->sole()->wants_reply);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->replyTo === []);
    }

    public function test_fixed_addresses_mode_mails_only_those(): void
    {
        Mail::fake();
        $this->admin(['email' => 'admin@example.org']);
        Setting::put(AppSettings::FEEDBACK_RECIPIENT_MODE, AppSettings::FEEDBACK_TO_FIXED);
        Setting::put(AppSettings::FEEDBACK_RECIPIENTS, ['x@example.org', 'y@example.org']);

        $this->submit($this->user())->assertRedirect();

        Mail::assertSent(KassierNotice::class, 2);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->hasTo('x@example.org'));
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->hasTo('y@example.org'));
        Mail::assertNotSent(KassierNotice::class, fn (KassierNotice $m) => $m->hasTo('admin@example.org'));
    }

    public function test_mail_failure_does_not_break_submission(): void
    {
        $this->admin(['email' => 'a@example.org']);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->submit($this->user())->assertRedirect(route('account.feedback'))->assertSessionHas('status');

        $this->assertSame(1, Feedback::query()->count());
    }

    public function test_validation(): void
    {
        $user = $this->user();

        $this->submit($user, ['message' => 'kurz'])->assertSessionHasErrors('message');
        $this->submit($user, ['message' => str_repeat('a', 5001)])->assertSessionHasErrors('message');
        $this->submit($user, ['message' => '  '])->assertSessionHasErrors('message');
        $this->submit($user, ['type' => 'lob'])->assertSessionHasErrors('type');

        $this->assertSame(0, Feedback::query()->count());
    }

    public function test_token_is_single_use(): void
    {
        Mail::fake();
        $this->actingAs($this->user());
        $data = $this->tokened('feedback', ['type' => 'feedback', 'message' => 'Alles super hier.']);

        $this->post('/konto/feedback', $data)->assertSessionHas('status');
        $this->post('/konto/feedback', $data)->assertSessionHas('warning');

        $this->assertSame(1, Feedback::query()->count());
    }

    public function test_rate_limit_per_user(): void
    {
        Mail::fake();
        $user = $this->user();
        RateLimiter::clear('feedback-user:'.$user->id);

        for ($i = 0; $i < 5; $i++) {
            $this->submit($user)->assertSessionHas('status');
        }
        $this->submit($user)->assertSessionHas('error');

        $this->assertSame(5, Feedback::query()->count());
        RateLimiter::clear('feedback-user:'.$user->id);
    }

    public function test_guest_and_left_user_cannot_submit(): void
    {
        $this->get('/konto/feedback')->assertRedirect(route('login'));
        $this->post('/konto/feedback', ['type' => 'idea', 'message' => 'Hallo Welt'])->assertRedirect(route('login'));

        $left = $this->user(['status' => User::STATUS_LEFT]);
        $this->submit($left)->assertRedirect(route('login'));

        $this->assertSame(0, Feedback::query()->count());
    }

    // --- Backend -----------------------------------------------------------------------

    public function test_admin_lists_marks_read_and_deletes(): void
    {
        $admin = $this->admin();
        $user = $this->user(['username' => 'hans']);
        $old = Feedback::query()->create(['user_id' => $user->id, 'type' => 'bug', 'message' => "Alt\nzweite Zeile"]);
        $new = Feedback::query()->create(['user_id' => $user->id, 'type' => 'idea', 'message' => 'Neu <b>fett</b>']);
        $this->actingAs($admin);

        $this->get('/admin/feedback')->assertOk()
            ->assertSeeInOrder(['Neu &lt;b&gt;fett&lt;/b&gt;', "Alt\nzweite Zeile"], false)
            ->assertSee('Von hans')
            ->assertSee('2 ungelesen');

        $this->post('/admin/feedback/'.$old->id.'/gelesen', ['read' => 1])->assertRedirect();
        $this->assertNotNull($old->refresh()->read_at);
        $this->post('/admin/feedback/'.$old->id.'/gelesen', ['read' => 0])->assertRedirect();
        $this->assertNull($old->refresh()->read_at);

        $this->delete('/admin/feedback/'.$new->id)->assertRedirect();
        $this->assertModelMissing($new);
    }

    public function test_kassier_and_user_forbidden_on_backend_page(): void
    {
        $feedback = Feedback::query()->create(['type' => 'bug', 'message' => 'Kaputt']);

        foreach ([$this->kassier(), $this->user()] as $who) {
            $this->actingAs($who);
            $this->get('/admin/feedback')->assertForbidden();
            $this->post('/admin/feedback/'.$feedback->id.'/gelesen', ['read' => 1])->assertForbidden();
            $this->delete('/admin/feedback/'.$feedback->id)->assertForbidden();
            $this->put('/admin/feedback/empfaenger', ['recipient_mode' => 'fixed', 'recipients' => 'x@example.org'])->assertForbidden();
        }

        $this->assertNull($feedback->refresh()->read_at);
        $this->assertSame(AppSettings::FEEDBACK_TO_ADMINS, AppSettings::feedbackRecipientMode());
    }

    public function test_nav_badge_shows_unread_count_for_admins_only(): void
    {
        Feedback::query()->create(['type' => 'bug', 'message' => 'Eins']);
        Feedback::query()->create(['type' => 'bug', 'message' => 'Zwei']);
        Feedback::query()->create(['type' => 'bug', 'message' => 'Drei', 'read_at' => now()]);

        $this->actingAs($this->admin());
        $this->get('/admin')->assertOk()
            ->assertSee(route('backend.settings'))
            ->assertSee('<span class="badge" title="2 ungelesen">2</span>', false);
        $this->get('/admin/einstellungen')->assertOk()->assertSee(route('backend.feedback'));

        $this->actingAs($this->kassier());
        $this->get('/admin')->assertOk()->assertDontSee(route('backend.feedback'))->assertDontSee('2 ungelesen');
    }

    // --- Recipients --------------------------------------------------------------------

    public function test_recipients_default_admins(): void
    {
        $this->assertSame(AppSettings::FEEDBACK_TO_ADMINS, AppSettings::feedbackRecipientMode());
        $this->actingAs($this->admin(['email' => 'boss@example.org']));

        $this->get('/admin/feedback')->assertOk()->assertSee('Aktuelle Empfänger: boss@example.org');
    }

    public function test_recipients_saved_from_list(): void
    {
        $this->actingAs($this->admin());

        $this->put('/admin/feedback/empfaenger', [
            'recipient_mode' => 'fixed',
            'recipients' => " x@example.org\ny@example.org, z@example.org;X@example.org\n\n",
        ])->assertRedirect(route('backend.feedback'))->assertSessionHasNoErrors();

        Setting::flushCache();
        $this->assertSame(AppSettings::FEEDBACK_TO_FIXED, AppSettings::feedbackRecipientMode());
        $this->assertSame(['x@example.org', 'y@example.org', 'z@example.org'], AppSettings::feedbackRecipients());
    }

    public function test_recipients_validation(): void
    {
        $this->actingAs($this->admin());

        $this->put('/admin/feedback/empfaenger', ['recipient_mode' => 'fixed', 'recipients' => ''])
            ->assertSessionHasErrors('recipients');
        $this->put('/admin/feedback/empfaenger', ['recipient_mode' => 'fixed', 'recipients' => "ok@example.org\nkeine-adresse"])
            ->assertSessionHasErrors(['recipients' => 'Ungültige E-Mail-Adresse: keine-adresse']);
        $this->put('/admin/feedback/empfaenger', ['recipient_mode' => 'egal', 'recipients' => 'ok@example.org'])
            ->assertSessionHasErrors('recipient_mode');

        Setting::flushCache();
        $this->assertSame(AppSettings::FEEDBACK_TO_ADMINS, AppSettings::feedbackRecipientMode());
        $this->assertSame([], AppSettings::feedbackRecipients());

        // Mode "admins" needs no addresses.
        $this->put('/admin/feedback/empfaenger', ['recipient_mode' => 'admins', 'recipients' => ''])
            ->assertSessionHasNoErrors();
    }

    public function test_app_version_is_stored_shown_and_mailed(): void
    {
        Mail::fake();
        $admin = $this->admin(['email' => 'a@example.org']);
        $version = SystemInfo::appVersion()['version'];

        $this->submit($this->user(), ['page' => '/konto'])->assertSessionHas('status');

        $feedback = Feedback::query()->sole();
        $this->assertSame($version, $feedback->app_version);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->rows['App-Version'] === $version);

        $this->actingAs($admin);
        $this->get('/admin/feedback')->assertOk()->assertSee('Seite: /konto')->assertSee('Version: '.$version);
    }

    public function test_feedback_without_version_shows_no_version(): void
    {
        $admin = $this->admin();
        Feedback::query()->create(['user_id' => $admin->id, 'type' => 'idea', 'message' => 'Alte Meldung ohne Version']);

        $this->actingAs($admin);
        $this->get('/admin/feedback')->assertOk()->assertSee('Alte Meldung ohne Version')->assertDontSee('Version: ');
    }
}

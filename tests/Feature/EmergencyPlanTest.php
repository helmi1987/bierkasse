<?php

namespace Tests\Feature;

use App\Livewire\Backend\EmergencyPlan as EmergencyPlanPage;
use App\Mail\KassierNotice;
use App\Models\AuditLog;
use App\Models\EmergencyPlanPrint;
use App\Models\Setting;
use App\Models\User;
use App\Services\EmergencyPlan;
use App\Support\AppSettings;
use App\Support\BackendNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Offline emergency plan (1.40.0, admins only): page, print view, stored prints, difference, settings, reminder mail.
 */
class EmergencyPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-08 09:14:00', 'Europe/Zurich'));
        $this->setPrice(200);
        $this->admin = $this->admin(['first_name' => 'Anna', 'last_name' => 'Admin', 'username' => 'aadmin', 'email' => 'anna@example.org']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function windowForm(array $values = []): array
    {
        return $values + ['side' => 'left', 'top' => '4.5', 'edge' => '2.2', 'width' => '10.0', 'height' => '4.5'];
    }

    private function textsForm(array $values = []): array
    {
        $texts = [];
        foreach (AppSettings::EMERGENCY_TEXT_KEYS as $key) {
            $texts[$key] = AppSettings::emergencyTextDefault($key);
        }

        return ['texts' => $values + $texts];
    }

    public function test_only_admins_see_the_page_and_the_menu_item(): void
    {
        $kassier = $this->kassier();
        $this->actingAs($kassier)->get('/admin/notfallplan')->assertForbidden();
        $this->get('/admin/notfallplan/testdruck')->assertForbidden();
        $this->post('/admin/notfallplan/drucken')->assertForbidden();
        $this->put('/admin/notfallplan/mail', ['threshold' => 1])->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee(route('backend.emergency'), false)->assertDontSee('Notfallplan');
        Livewire::actingAs($kassier)->test(EmergencyPlanPage::class)->assertForbidden();
        $this->assertSame(0, EmergencyPlanPrint::query()->count());

        $this->actingAs($this->user())->get('/admin/notfallplan')->assertForbidden();

        $this->actingAs($this->admin)->get('/admin/notfallplan')->assertOk()
            ->assertSeeLivewire(EmergencyPlanPage::class)
            ->assertSee('href="'.route('backend.emergency').'" class="active"', false)
            ->assertSee(__('emergency.never'))->assertSee(__('emergency.print'))->assertSee(__('emergency.test_print'));
        $this->get('/admin/einstellungen')->assertOk()->assertSee('href="'.route('backend.emergency').'"', false);
        $this->assertContains('emergency', array_keys(BackendNav::ITEMS[BackendNav::BACKEND]));
    }

    public function test_list_contains_active_users_with_beer_account_sorted_and_contacts_once(): void
    {
        $this->user(['first_name' => 'Zora', 'last_name' => 'Zeller', 'username' => 'zz']);
        $this->user(['first_name' => 'Beat', 'last_name' => 'Muster', 'username' => 'bmuster']);
        $this->user(['first_name' => 'Lea', 'last_name' => 'Weg', 'status' => User::STATUS_LEFT]);
        $this->user(['first_name' => 'Gerd', 'last_name' => 'Gesperrt', 'status' => User::STATUS_LOCKED]);
        $this->kassier(['first_name' => 'Kurt', 'last_name' => 'Kassier', 'username' => 'kkassier']);
        $this->kassier(['first_name' => 'Vera', 'last_name' => 'Verwaltung', 'admin_only' => true]);
        $this->admin(['first_name' => 'Otto', 'last_name' => 'Oberadmin', 'status' => User::STATUS_LEFT]);

        $names = EmergencyPlan::users()->map(fn (User $u) => $u->displayName())->all();
        $this->assertSame(['Anna Admin', 'Beat Muster', 'Kurt Kassier', 'Zora Zeller'], $names);
        $this->assertSame(['kassier' => ['Kurt Kassier', 'Vera Verwaltung'], 'admin' => ['Anna Admin']], EmergencyPlan::contacts());

        $html = $this->actingAs($this->admin)->get('/admin/notfallplan/testdruck')->assertOk()->getContent();
        // Test print: page 1 only, window frame visible, nothing stored.
        $this->assertStringContainsString('<body class="test">', $html);
        $this->assertStringNotContainsString('data-testid="emergency-list-page"', $html);
        $this->assertStringContainsString(__('emergency.test_page'), $html);
        $this->assertStringContainsString('<b>Bei Ausfall bitte melden:</b> Kassier Kurt Kassier, Vera Verwaltung · Admin Anna Admin', $html);
        $this->assertStringContainsString('Bierpreis: CHF 2.00 pro Bier.', $html);
        $this->assertStringContainsString('Stand 08.11.2026', $html);
        $this->assertSame(0, EmergencyPlanPrint::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'emergency_plan.print')->count());
    }

    public function test_print_is_stored_with_audit_and_shows_all_pages(): void
    {
        $this->kassier(['first_name' => 'Kurt', 'last_name' => 'Kassier']);
        for ($i = 1; $i <= 60; $i++) {
            $this->user(['first_name' => 'User', 'last_name' => sprintf('%02d', $i), 'username' => sprintf('u%02d', $i)]);
        }
        $this->user(['first_name' => 'Weg', 'last_name' => 'Gegangen', 'status' => User::STATUS_LEFT]);

        $response = $this->actingAs($this->admin)->post('/admin/notfallplan/drucken');
        $print = EmergencyPlanPrint::query()->sole();
        $response->assertRedirect(route('backend.emergency.sheet', $print));
        $this->assertSame($this->admin->id, $print->printed_by);
        $this->assertSame(62, $print->user_count);
        $this->assertCount(62, $print->userIds());

        $audit = AuditLog::query()->where('action', 'emergency_plan.print')->sole();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertEquals(['user_count' => 62, 'new_users' => 62, 'left_users' => 0], $audit->newValues());

        $html = $this->get(route('backend.emergency.sheet', $print))->assertOk()->getContent();
        $this->assertStringContainsString('<body class="">', $html);
        $this->assertSame(62, substr_count($html, 'data-testid="emergency-user"'));
        $this->assertStringNotContainsString('Weg Gegangen', $html);
        // 62 users = 21 rows; 17 rows per page -> list pages 2 and 3, the bar and blank fields to the end of page 3.
        $this->assertSame(2, substr_count($html, 'data-testid="emergency-list-page"'));
        $this->assertStringContainsString('Seite 1/3', $html);
        $this->assertStringContainsString('Seite 3/3', $html);
        $this->assertSame(1, substr_count($html, __('emergency.new_users_bar')));
        $this->assertSame(3 * 13, substr_count($html, '<div class="nm blank">'));
        $this->assertStringContainsString('Ausdruck: 08.11.2026 09:14 · Anna Admin', $html);
        $this->assertStringContainsString('Bierpreis: CHF 2.00', $html);
        $this->assertStringContainsString('(Kassier), Anna Admin (Admin)', $html);
        $this->assertStringContainsString('Nachgebucht am: __________ Kassier: __________', $html);
        // Sorted by name.
        $this->assertLessThan(strpos($html, 'User 02'), strpos($html, 'User 01'));
        $this->assertLessThan(strpos($html, 'Kurt Kassier</div>'), strpos($html, 'Anna Admin</div>'));
    }

    public function test_page_layout_fills_the_last_page(): void
    {
        $perPage = (int) floor((EmergencyPlan::PAGE_MM - EmergencyPlan::HEAD_MM - EmergencyPlan::FOOT_MM - EmergencyPlan::FRAME_MM) / EmergencyPlan::ROW_MM);
        $this->assertSame(17, $perPage);

        // No users: one page with the bar and blank rows only.
        $pages = EmergencyPlan::pages([]);
        $this->assertCount(1, $pages);
        $this->assertSame('sep', $pages[0][0]['type']);
        $this->assertCount(17, array_filter($pages[0], fn ($r) => $r['type'] === 'blank'));

        // 51 users = exactly 17 full rows: the bar does not fit, it starts a new page filled with blank rows.
        $users = User::factory()->count(51)->make()->all();
        $pages = EmergencyPlan::pages($users);
        $this->assertCount(2, $pages);
        $this->assertCount(17, $pages[0]);
        $this->assertSame('sep', $pages[1][0]['type']);

        // 31 users: 11 rows (last one with one user and two empty cells), bar, 6 blank rows.
        $pages = EmergencyPlan::pages(User::factory()->count(31)->make()->all());
        $this->assertCount(1, $pages);
        $this->assertSame([null, null], array_slice($pages[0][10]['cells'], 1));
        $this->assertSame('sep', $pages[0][11]['type']);
        $this->assertCount(6, array_slice($pages[0], 12));
        foreach (array_slice($pages[0], 12) as $row) {
            $this->assertSame('blank', $row['type']);
        }
    }

    public function test_placeholders_are_replaced_and_texts_escaped(): void
    {
        $this->actingAs($this->admin)->put('/admin/notfallplan/texte', $this->textsForm([
            'heading' => 'Plan für {anzahl} Leute <script>x</script>',
            'window_notice' => 'Gedruckt {datum} von {person}, **wichtig**',
        ]))->assertRedirect(route('backend.emergency').'#texte')->assertSessionHasNoErrors();

        $html = $this->get('/admin/notfallplan/testdruck')->assertOk()->getContent();
        $this->assertStringContainsString('Plan für 1 Leute &lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('Gedruckt 08.11.2026 von Anna Admin, <b>wichtig</b>', $html);
        $this->assertStringNotContainsString('{anzahl}', $html);
    }

    public function test_texts_are_stored_only_when_changed_and_can_be_reset(): void
    {
        $this->actingAs($this->admin)->put('/admin/notfallplan/texte', $this->textsForm(['footer' => "Links\nRechts"]))->assertSessionHasNoErrors();
        $this->assertSame(['footer' => "Links\nRechts"], AppSettings::emergencyTextsStored());
        $audit = AuditLog::query()->where('action', 'settings.update')->latest('id')->first();
        $this->assertSame("Links\nRechts", $audit->newValues()['emergency_texts.footer']);

        $this->get('/admin/notfallplan')->assertOk()->assertSee('id="emergency-reset-footer"', false)->assertSee(__('emergency.text_reset_button'));

        $this->delete('/admin/notfallplan/texte/footer')->assertRedirect(route('backend.emergency').'#texte')->assertSessionHas('status');
        $this->assertSame([], AppSettings::emergencyTextsStored());
        $this->assertSame(__('emergency.defaults.footer'), AppSettings::emergencyText('footer'));
        $this->delete('/admin/notfallplan/texte/unknown')->assertNotFound();

        // Required and limited.
        $this->put('/admin/notfallplan/texte', $this->textsForm(['heading' => '', 'window_title' => str_repeat('x', 61)]))
            ->assertSessionHasErrors(['texts.heading', 'texts.window_title']);
    }

    public function test_window_settings_validated_against_a4(): void
    {
        $this->actingAs($this->admin);
        $this->assertSame(['side' => 'right', 'top' => 40, 'edge' => 20, 'width' => 100, 'height' => 45], AppSettings::emergencyWindow());

        $this->put('/admin/notfallplan/fenster', $this->windowForm())->assertSessionHasNoErrors()->assertRedirect(route('backend.emergency'));
        $this->assertSame(['side' => 'left', 'top' => 45, 'edge' => 22, 'width' => 100, 'height' => 45], AppSettings::emergencyWindow());
        $audit = AuditLog::query()->where('action', 'settings.update')->latest('id')->first();
        $this->assertEquals(['emergency_window.side' => 'left', 'emergency_window.top' => 45, 'emergency_window.edge' => 22, 'emergency_window.width' => 100, 'emergency_window.height' => 45], $audit->newValues());

        // Print view uses it (inside the 10 mm page margin).
        $html = $this->get('/admin/notfallplan/testdruck')->getContent();
        $this->assertStringContainsString('top: 35mm; left: 12mm; width: 100mm; height: 45mm;', $html);

        $this->put('/admin/notfallplan/fenster', $this->windowForm(['edge' => '12.0', 'width' => '10.0']))->assertSessionHasErrors('width');
        $this->put('/admin/notfallplan/fenster', $this->windowForm(['top' => '25.0', 'height' => '4.5']))->assertSessionHasErrors('height');
        $this->put('/admin/notfallplan/fenster', $this->windowForm(['top' => '0.5']))->assertSessionHasErrors('top');
        $this->put('/admin/notfallplan/fenster', $this->windowForm(['top' => '4.55', 'side' => 'oben', 'width' => '30']))->assertSessionHasErrors(['top', 'side', 'width']);
        $this->put('/admin/notfallplan/fenster', $this->windowForm(['height' => '1.0']))->assertSessionHasErrors('height');
        $this->assertSame(45, AppSettings::emergencyWindow()['top']);
        // Limits exactly at the margin are fine.
        $this->put('/admin/notfallplan/fenster', $this->windowForm(['top' => '1.0', 'edge' => '1.0', 'width' => '19.0', 'height' => '27.7']))->assertSessionHasNoErrors();
    }

    public function test_difference_counts_new_and_left_users(): void
    {
        $stay = $this->user(['first_name' => 'Stefan', 'last_name' => 'Bleibt']);
        $leave = $this->user(['first_name' => 'Peter', 'last_name' => 'Weg']);
        $lock = $this->user(['first_name' => 'Gerd', 'last_name' => 'Gesperrt']);

        // Never printed: every listed user is new.
        $diff = EmergencyPlan::difference();
        $this->assertSame(4, $diff['count']);
        $this->assertSame(4, $diff['new']->count());

        EmergencyPlan::record($this->admin);
        $this->assertSame(0, EmergencyPlan::difference()['count']);

        $leave->forceFill(['status' => User::STATUS_LEFT])->save();
        $lock->forceFill(['status' => User::STATUS_LOCKED])->save();
        $this->user(['first_name' => 'Mia', 'last_name' => 'Neu']);
        Setting::put(AppSettings::EMERGENCY_MAIL_THRESHOLD, 2);
        $diff = EmergencyPlan::difference();
        $this->assertSame(3, $diff['count']);
        $this->assertSame(['Mia Neu'], $diff['new']->map->displayName()->all());
        $this->assertSame(['Gerd Gesperrt', 'Peter Weg'], $diff['left']->map->displayName()->all());

        $this->actingAs($this->admin)->get('/admin/notfallplan')->assertOk()
            ->assertSee('data-testid="emergency-difference">3<', false)
            ->assertSee('1 neu: Mia Neu')->assertSee('2 ausgetreten: Gerd Gesperrt, Peter Weg')
            ->assertSee('data-testid="emergency-over-threshold"', false)
            ->assertSee('Differenz 3 ist grösser als die Schwelle', false);
        $this->assertTrue($stay->isActive());
    }

    public function test_mail_settings_validated(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        $this->actingAs($this->admin)->put('/admin/notfallplan/mail', ['threshold' => 5, 'recipients' => [$kassier->id, $this->admin->id]])
            ->assertSessionHasNoErrors()->assertRedirect(route('backend.emergency').'#mail');
        $this->assertSame(5, AppSettings::emergencyMailThreshold());
        $this->assertSame([$this->admin->id, $kassier->id], AppSettings::emergencyMailRecipients());

        $this->put('/admin/notfallplan/mail', ['threshold' => -1, 'recipients' => [$user->id]])->assertSessionHasErrors(['threshold', 'recipients.0']);
        $this->put('/admin/notfallplan/mail', ['threshold' => 0])->assertSessionHasNoErrors();
        $this->assertSame([0, []], [AppSettings::emergencyMailThreshold(), AppSettings::emergencyMailRecipients()]);
    }

    public function test_reminder_mail_threshold_weekly_and_stop_after_print(): void
    {
        Mail::fake();
        $kassier = $this->kassier(['email' => 'kurt@example.org']);
        Setting::putMany([AppSettings::EMERGENCY_MAIL_THRESHOLD => 2]);
        EmergencyPlan::record($this->admin);

        // No recipients: no mail.
        $this->user();
        $this->user();
        $this->user();
        $this->artisan('emergency-plan:reminders')->assertSuccessful();
        Mail::assertNothingSent();

        // Difference 3 > 2: mail to the chosen recipient.
        Setting::put(AppSettings::EMERGENCY_MAIL_RECIPIENTS, [$kassier->id]);
        $this->artisan('emergency-plan:reminders --dry-run')->assertSuccessful();
        Mail::assertNothingSent();
        $this->artisan('emergency-plan:reminders')->assertSuccessful();
        Mail::assertSentCount(1);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->hasTo('kurt@example.org')
            && str_contains($m->noticeSubject, '3 Änderungen') && $m->url === route('backend.emergency'));
        $this->assertNotNull(AppSettings::emergencyMailSentAt());
        $this->assertSame(1, AuditLog::query()->where('action', 'emergency_plan.reminder')->count());

        // At most weekly.
        $this->travel(6)->days();
        $this->artisan('emergency-plan:reminders');
        Mail::assertSentCount(1);
        $this->travel(1)->days();
        $this->artisan('emergency-plan:reminders');
        Mail::assertSentCount(2);

        // New print: difference 0, no more mails.
        EmergencyPlan::record($this->admin);
        $this->travel(8)->days();
        $this->artisan('emergency-plan:reminders');
        Mail::assertSentCount(2);

        // Not above the threshold (2 = 2): no mail.
        $this->user();
        $this->user();
        $this->artisan('emergency-plan:reminders');
        Mail::assertSentCount(2);
    }

    public function test_reminder_skips_recipients_without_address_or_role(): void
    {
        Mail::fake();
        $former = $this->kassier(['email' => 'former@example.org']);
        $noMail = $this->admin(['email' => null]);
        Setting::putMany([AppSettings::EMERGENCY_MAIL_THRESHOLD => 0, AppSettings::EMERGENCY_MAIL_RECIPIENTS => [$former->id, $noMail->id]]);
        $former->forceFill(['role' => User::ROLE_USER])->save();

        $result = EmergencyPlan::remind();
        $this->assertSame(['due' => true, 'reason' => 'no_recipients', 'sent' => 0], array_intersect_key($result, array_flip(['due', 'reason', 'sent'])));
        Mail::assertNothingSent();
        $this->assertNull(AppSettings::emergencyMailSentAt());
    }
}

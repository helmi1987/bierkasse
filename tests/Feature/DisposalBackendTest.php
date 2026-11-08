<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Disposal;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Disposals;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Entsorgungsbonus" (1.32.0), backend page "Entsorgung": rights, settings, report for a user, override, storno.
 */
class DisposalBackendTest extends TestCase
{
    use RefreshDatabase;

    private User $kassier;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->setDateTime(2026, 11, 6, 12, 0, 0));
        $this->kassier = $this->kassier(['first_name' => 'Kurt', 'last_name' => 'Kassier']);
        $this->user = $this->user(['first_name' => 'Ursi', 'last_name' => 'User']);
    }

    private function enable(array $settings = []): void
    {
        Setting::putMany($settings + [
            AppSettings::DISPOSAL_ENABLED => true,
            AppSettings::DISPOSAL_ENABLED_AT => now()->format(DATE_ATOM),
            AppSettings::DISPOSAL_FIXED => 2,
        ]);
    }

    private function settingsForm(array $values = []): array
    {
        return $values + [
            'disposal_enabled' => '1', 'disposal_mode' => 'random', 'disposal_fixed' => 4, 'disposal_random_max' => 6,
            'disposal_lock_days' => 3, 'disposal_min_beers' => 12, 'disposal_withdraw_minutes' => 15, 'disposal_ranking_public' => '1',
        ];
    }

    public function test_rights_user_403_kassier_and_admin_200(): void
    {
        $this->actingAs($this->user)->get('/admin/lager')->assertForbidden();
        $this->post('/admin/entsorgung', ['user_id' => $this->user->id])->assertForbidden();
        $this->actingAs($this->kassier)->get('/admin/lager')->assertOk()
            ->assertSee('href="'.route('backend.stock').'" class="active"', false);
        $this->actingAs($this->admin())->get('/admin/lager')->assertOk();
    }

    public function test_area_is_on_the_stock_page_without_own_tab_and_old_page_redirects(): void
    {
        // 1.39.0: the whole page "Entsorgung" is an area below the stock, the tab is gone.
        $page = $this->actingAs($this->kassier)->get('/admin/lager')->assertOk();
        $html = $page->getContent();
        $page->assertSee('id="entsorgung"', false)->assertSee(__('disposal.intro'))
            ->assertSee('data-testid="disposal-guide"', false)->assertSee('data-testid="disposal-faq"', false)
            ->assertSee('data-testid="disposal-settings"', false)->assertSee('data-testid="backend-disposal-ranking"', false)
            ->assertDontSee('href="'.url('/admin/entsorgung').'"', false);
        // Below the stock content.
        $this->assertLessThan(strpos($html, 'id="entsorgung"'), strpos($html, 'data-testid="backend-stock"'));

        $this->get('/admin/entsorgung')->assertStatus(301)->assertRedirect(route('backend.stock').'#entsorgung');
        $this->actingAs($this->user)->get('/admin/entsorgung')->assertForbidden();

        // The form posts go back to the area.
        $this->actingAs($this->kassier)->put('/admin/entsorgung/einstellungen', $this->settingsForm())
            ->assertRedirect(route('backend.stock').'#entsorgung');
        $this->put('/admin/entsorgung/einstellungen', $this->settingsForm(['disposal_fixed' => 99]))
            ->assertRedirect(route('backend.stock').'#entsorgung')->assertSessionHasErrors('disposal_fixed');
        $this->post('/admin/entsorgung', ['user_id' => $this->user->id])->assertRedirect(route('backend.stock').'#entsorgung');
    }

    public function test_feature_off_page_shows_guide_and_faq_collapsed_and_no_report_form(): void
    {
        $page = $this->actingAs($this->kassier)->get('/admin/lager')->assertOk();
        // 1.38.2: guide and FAQ are always collapsed, also when the feature is off.
        $this->assertDoesNotMatchRegularExpression('/data-testid="disposal-(guide|faq)"\s+open/', $page->getContent());
        $page->assertSee(__('disposal.guide')[1])->assertSee('data-testid="disposal-faq"', false)
            ->assertSee(__('disposal.faq_heading'))->assertSee(__('disposal.faq')[0]['q'])->assertSee(__('disposal.faq')[11]['a'])
            ->assertSee('data-testid="disposal-settings"', false)->assertDontSee('data-testid="disposal-for-user"', false);
        $this->post('/admin/entsorgung', ['user_id' => $this->user->id])->assertSessionHas('error', __('disposal.disabled'));
        $this->assertSame(0, Disposal::query()->count());
    }

    public function test_kassier_saves_settings_with_audit(): void
    {
        Setting::put(AppSettings::UNDO_MINUTES, ['consume' => 7, 'gift' => 3]);
        $this->actingAs($this->kassier)->put('/admin/entsorgung/einstellungen', $this->settingsForm())
            ->assertSessionHasNoErrors()->assertSessionHas('status', __('disposal.saved'));

        $this->assertTrue(AppSettings::disposalEnabled());
        $this->assertNotNull(AppSettings::disposalEnabledAt());
        $this->assertSame(['random', 4, 6, 3, 12, 15, true], [
            AppSettings::disposalMode(), AppSettings::disposalFixed(), AppSettings::disposalRandomMax(),
            AppSettings::disposalLockDays(), AppSettings::disposalMinBeers(), AppSettings::undoMinutes('disposal'), AppSettings::disposalRankingPublic(),
        ]);
        // Other undo windows are kept.
        $this->assertSame([7, 3], [AppSettings::undoMinutes('consume'), AppSettings::undoMinutes('gift')]);

        $audit = AuditLog::query()->where('action', 'settings.update')->latest('id')->first();
        $new = $audit->newValues();
        $this->assertSame($this->kassier->id, $audit->actor_id);
        $this->assertTrue($new['disposal_enabled']);
        $this->assertSame(15, $new['undo_minutes.disposal']);
        $this->actingAs($this->kassier)->get('/admin/protokoll/'.$audit->id)->assertOk()->assertSee('Rückzugsfrist Entsorgung (Min.)');

        // Saving again while on keeps the restart time.
        $at = AppSettings::get(AppSettings::DISPOSAL_ENABLED_AT);
        $this->travel(1)->hours();
        $this->put('/admin/entsorgung/einstellungen', $this->settingsForm());
        $this->assertSame($at, AppSettings::get(AppSettings::DISPOSAL_ENABLED_AT));

        // Validation with field errors.
        $this->put('/admin/entsorgung/einstellungen', $this->settingsForm(['disposal_fixed' => 21, 'disposal_lock_days' => -1, 'disposal_withdraw_minutes' => 61, 'disposal_mode' => 'x']))
            ->assertSessionHasErrors(['disposal_fixed', 'disposal_lock_days', 'disposal_withdraw_minutes', 'disposal_mode']);

        // The admin settings form no longer has the disposal window and keeps it when saved.
        $this->actingAs($this->admin())->get('/admin/einstellungen')->assertOk()->assertDontSee('undo_minutes[disposal]', false);
    }

    public function test_report_for_user_books_to_the_recipient_and_only_kassier_can_withdraw(): void
    {
        $this->enable();
        $page = $this->actingAs($this->kassier)->get('/admin/lager')->assertOk();
        $page->assertSee('data-testid="disposal-for-user"', false)->assertSee('Ursi User')
            ->assertDontSee('<option value="'.$this->kassier->id.'"', false)->assertSee(__('disposal.guide')[0]);
        $this->assertDoesNotMatchRegularExpression('/data-testid="disposal-guide"\s+open/', $page->getContent());

        $this->post('/admin/entsorgung', ['user_id' => $this->user->id])->assertSessionHas('status', __('disposal.reported_for', ['user' => 'Ursi User']));
        $d = Disposal::query()->sole();
        $this->assertSame([$this->user->id, $this->kassier->id, Disposal::STATUS_REPORTED], [$d->user_id, $d->reported_by, $d->status]);
        $this->get('/admin/lager')->assertSee('erfasst von Kurt Kassier')->assertSee('gemeldet')->assertSee('Buchung am 12:10');

        // The user sees it registered, without the withdraw button, and cannot withdraw it.
        $this->actingAs($this->user)->get('/konto')->assertOk()->assertSee('Entsorgung wurde registriert.')
            ->assertDontSee('data-testid="disposal-withdraw"', false);
        $this->post("/konto/entsorgung/{$d->id}/zurueckziehen")->assertSessionHas('error', __('disposal.withdraw_expired'));
        $this->assertTrue($d->fresh()->isReported());

        // Booking goes to the recipient.
        $this->travel(11)->minutes();
        $this->assertSame(1, Disposals::bookDue());
        $this->assertSame(300, Ledger::balance($this->user));
        $this->assertSame(0, Ledger::balance($this->kassier));
        $this->actingAs($this->kassier)->get('/admin/lager')->assertSee('gebucht')->assertSee('2 Biere')->assertSee('CHF 3.00');
    }

    public function test_kassier_withdraws_a_report_inside_the_window(): void
    {
        $this->enable();
        Disposals::report($this->user, $this->user);
        $d = Disposal::query()->sole();
        $this->actingAs($this->kassier)->get('/admin/lager')->assertSee(route('backend.disposals.withdraw', $d), false);
        $this->post("/admin/entsorgung/{$d->id}/zurueckziehen")->assertSessionHas('status', __('disposal.withdrawn'));
        $this->assertSame([Disposal::STATUS_WITHDRAWN, $this->kassier->id], [$d->fresh()->status, $d->fresh()->withdrawn_by]);
        $this->get('/admin/lager')->assertSee('zurückgezogen von Kurt Kassier');
    }

    public function test_self_report_via_backend_is_rejected(): void
    {
        $this->enable();
        $this->actingAs($this->kassier)->post('/admin/entsorgung', ['user_id' => $this->kassier->id])->assertSessionHas('error', __('disposal.self_forbidden'));
        $this->assertSame(0, Disposal::query()->count());
    }

    public function test_blocked_needs_override_with_reason(): void
    {
        $this->enable([AppSettings::DISPOSAL_LOCK_DAYS => 7]);
        Disposals::report($this->user, $this->user);
        $other = $this->user(['first_name' => 'Otto']);

        $this->actingAs($this->kassier)->post('/admin/entsorgung', ['user_id' => $other->id])->assertSessionHas('error', __('disposal.blocked_backend'));
        $this->post('/admin/entsorgung', ['user_id' => $other->id, 'override' => '1'])->assertSessionHasErrors(['reason' => __('disposal.reason_required')]);
        $this->assertSame(1, Disposal::query()->count());

        $this->post('/admin/entsorgung', ['user_id' => $other->id, 'override' => '1', 'reason' => 'Hat doppelt geschleppt'])->assertSessionHas('status');
        $d = Disposal::query()->latest('id')->first();
        $this->assertSame([$other->id, 'Hat doppelt geschleppt'], [$d->user_id, $d->override_reason]);
        $audit = AuditLog::query()->where('action', 'disposal.report')->latest('id')->first();
        $this->assertSame('Hat doppelt geschleppt', $audit->newValues()['override_reason']);
        $this->get('/admin/lager')->assertSee('Übersteuert: Hat doppelt geschleppt');

        // Users can never override (service level).
        $this->expectException(LedgerException::class);
        Disposals::report($this->kassier, $this->kassier, true, 'selbst');
    }

    public function test_storno_of_a_booked_bonus(): void
    {
        $this->enable();
        Disposals::report($this->user, $this->user);
        $this->travel(11)->minutes();
        Disposals::bookDue();
        $tx = Transaction::query()->where('type', Transaction::TYPE_DISPOSAL)->sole();
        $this->assertSame(300, Ledger::balance($this->user));

        $this->actingAs($this->kassier)->get("/admin/benutzer/{$this->user->id}")->assertOk()
            ->assertSee(route('backend.users.storno', [$this->user, $tx]), false)->assertSee('Leergut-Bonus');
        $this->post("/admin/benutzer/{$this->user->id}/buchungen/{$tx->id}/storno", ['note' => 'Irrtum'])->assertSessionHas('status', __('ledger.storno_done'));

        $this->assertSame(0, Ledger::balance($this->user));
        $d = Disposal::query()->sole();
        $this->assertSame([Disposal::STATUS_BOOKED, null, 2], [$d->status, $d->transaction_id, $d->quantity]);
        $this->assertTrue(AuditLog::query()->where('action', 'disposal.storno')->exists());
        $this->get('/admin/lager')->assertOk()->assertSee(__('disposal.storno_note'));
    }

    public function test_inactive_recipient_is_skipped_at_booking(): void
    {
        $this->enable();
        Disposals::report($this->user, $this->user);
        $this->user->forceFill(['status' => User::STATUS_LEFT])->save();
        $this->travel(11)->minutes();
        $this->assertSame(0, Disposals::bookDue());
        $d = Disposal::query()->sole();
        $this->assertSame([Disposal::STATUS_WITHDRAWN, null], [$d->status, $d->withdrawn_by]);
        $this->assertTrue(AuditLog::query()->where('action', 'disposal.skip')->exists());
        $this->assertSame(0, Transaction::query()->where('type', Transaction::TYPE_DISPOSAL)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\AuditLog;
use App\Models\CashEntry;
use App\Models\Disposal;
use App\Models\GuestSale;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\Disposals;
use App\Services\Ledger;
use App\Services\Stats;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Entsorgungsbonus" (1.32.0), user side: card on the dashboard, report, withdraw, booking, lock and minimum.
 */
class DisposalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->setDateTime(2026, 11, 6, 12, 0, 0));
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-11-01', 'amount' => -10000, 'beers' => 100, 'method' => 'cash', 'note' => 'Kauf']);
        $this->kassier(['email' => 'kassier@example.org']);
        $this->user = $this->user(['first_name' => 'Edi', 'last_name' => 'Entsorger']);
    }

    /** Switches the feature on ("restart" now) with the given settings. */
    private function enable(array $settings = []): void
    {
        Setting::putMany($settings + [
            AppSettings::DISPOSAL_ENABLED => true,
            AppSettings::DISPOSAL_ENABLED_AT => now()->format(DATE_ATOM),
            AppSettings::DISPOSAL_MODE => AppSettings::DISPOSAL_MODE_FIXED,
            AppSettings::DISPOSAL_FIXED => 3,
        ]);
    }

    private function report(User $user)
    {
        return $this->actingAs($user)->post('/konto/entsorgung', $this->tokened('disposal'));
    }

    /** Beers leaving the stock: confirmed consumption rows of a helper user. */
    private function drink(int $beers): void
    {
        $drinker = User::query()->where('username', 'drinker')->first() ?? $this->user(['username' => 'drinker']);
        Transaction::query()->create([
            'user_id' => $drinker->id, 'type' => Transaction::TYPE_CONSUMPTION, 'status' => Transaction::STATUS_CONFIRMED,
            'quantity' => $beers, 'unit_price' => 150, 'amount' => -150 * $beers, 'created_by' => $drinker->id,
        ]);
    }

    public function test_feature_off_shows_nothing_and_rejects_reports(): void
    {
        $this->actingAs($this->user)->get('/konto')->assertOk()->assertDontSee('data-testid="disposal"', false);
        $this->report($this->user)->assertRedirect()->assertSessionHas('error', __('disposal.disabled'));
        $this->assertSame(0, Disposal::query()->count());
    }

    public function test_report_registers_without_quantity_and_without_booking(): void
    {
        $this->enable();
        $this->actingAs($this->user)->get('/konto')->assertOk()
            ->assertSee('data-testid="disposal"', false)->assertSee('Leergut entsorgt?')->assertSee(__('disposal.intro'));

        $this->report($this->user)->assertSessionHas('status', __('disposal.reported'));

        $d = Disposal::query()->sole();
        $this->assertSame([Disposal::STATUS_REPORTED, $this->user->id, $this->user->id, null, null], [$d->status, $d->user_id, $d->reported_by, $d->quantity, $d->transaction_id]);
        $this->assertSame(0, Ledger::balance($this->user));
        $this->assertSame(0, Transaction::query()->where('type', Transaction::TYPE_DISPOSAL)->count());

        $audit = AuditLog::query()->where('action', 'disposal.report')->sole();
        $this->assertSame(['reported_by' => $this->user->id, 'user_id' => $this->user->id], $audit->newValues());
        $this->assertStringNotContainsString('quantity', (string) $audit->new_values);

        Mail::assertSent(KassierNotice::class, function (KassierNotice $m) {
            $html = $m->render();

            return $m->hasTo('kassier@example.org') && str_contains($m->noticeSubject, 'Edi Entsorger')
                && ! str_contains($html, '3 Bier') && ! preg_match('/\b3\s*Biere?\b/u', implode(' ', $m->rows))
                && ! str_contains(strtolower($html), 'anzahl');
        });

        $this->actingAs($this->user)->get('/konto')->assertOk()
            ->assertSee('Entsorgung wurde registriert.')->assertSee('data-testid="disposal-withdraw"', false)
            ->assertDontSee(__('disposal.intro'))->assertDontSee('Leergut-Bonus');
    }

    public function test_other_user_sees_nothing_during_the_lock_and_gets_the_generic_message(): void
    {
        $this->enable([AppSettings::DISPOSAL_LOCK_DAYS => 7, AppSettings::DISPOSAL_MIN_BEERS => 0]);
        $this->report($this->user);
        $other = $this->user();

        $this->actingAs($other)->get('/konto')->assertOk()->assertDontSee('data-testid="disposal"', false)->assertDontSee('Leergut');
        $this->report($other)->assertSessionHas('error', __('disposal.blocked'));
        $this->assertSame(1, Disposal::query()->count());

        // After the booking and still inside the lock: still nothing, no hint, no reporter.
        $this->travel(1)->days();
        $this->artisan('disposals:book')->assertSuccessful();
        $this->actingAs($other)->get('/konto')->assertOk()->assertDontSee('data-testid="disposal"', false)->assertDontSee('Leergut')->assertDontSee('Entsorgung');
        // After the lock: possible again.
        $this->travel(7)->days();
        $this->actingAs($other)->get('/konto')->assertOk()->assertSee('data-testid="disposal"', false);
    }

    public function test_withdraw_inside_the_window_lifts_the_lock(): void
    {
        $this->enable([AppSettings::DISPOSAL_LOCK_DAYS => 7]);
        $this->report($this->user);
        $d = Disposal::query()->sole();

        $this->travel(5)->minutes();
        $this->actingAs($this->user)->post("/konto/entsorgung/{$d->id}/zurueckziehen")->assertSessionHas('status', __('disposal.withdrawn'));
        $d->refresh();
        $this->assertSame([Disposal::STATUS_WITHDRAWN, $this->user->id], [$d->status, $d->withdrawn_by]);
        $this->assertTrue(AuditLog::query()->where('action', 'disposal.withdraw')->exists());

        $this->travel(30)->minutes();
        $this->assertSame(0, Disposals::bookDue());
        $this->assertSame(0, Ledger::balance($this->user));

        $other = $this->user();
        $this->report($other)->assertSessionHas('status', __('disposal.reported'));
        $this->assertSame(1, Disposal::query()->reported()->count());
    }

    public function test_withdraw_after_the_window_fails(): void
    {
        $this->enable();
        $this->report($this->user);
        $d = Disposal::query()->sole();
        $this->travel(11)->minutes();
        $this->actingAs($this->user)->post("/konto/entsorgung/{$d->id}/zurueckziehen")->assertSessionHas('error', __('disposal.withdraw_expired'));
        // Another user's disposal: 404.
        $this->actingAs($this->user())->post("/konto/entsorgung/{$d->id}/zurueckziehen")->assertNotFound();
    }

    public function test_booking_after_the_window_by_command_is_idempotent(): void
    {
        $this->enable();
        $this->setPrice(200);
        $stock = BeerStock::current();
        $this->report($this->user);

        $this->travel(9)->minutes();
        $this->artisan('disposals:book')->assertSuccessful();
        $this->assertSame(0, Transaction::query()->where('type', Transaction::TYPE_DISPOSAL)->count());

        $this->travel(2)->minutes();
        $this->artisan('disposals:book')->assertSuccessful()->expectsOutput('1 disposal bonus(es) booked.');
        $this->artisan('disposals:book')->expectsOutput('0 disposal bonus(es) booked.');
        $this->assertSame(0, Disposals::bookDue());

        $tx = Transaction::query()->where('type', Transaction::TYPE_DISPOSAL)->sole();
        $d = Disposal::query()->sole();
        $this->assertSame([3, 200, 600, Transaction::STATUS_CONFIRMED, $this->user->id, null], [$tx->quantity, $tx->unit_price, $tx->amount, $tx->status, $tx->created_by, $tx->note]);
        $this->assertSame([Disposal::STATUS_BOOKED, 3, $tx->id], [$d->status, $d->quantity, $d->transaction_id]);
        $this->assertNotNull($d->booked_at);
        $this->assertEquals(['quantity' => 3, 'amount' => 600, 'transaction_id' => $tx->id, 'user_id' => $this->user->id], AuditLog::query()->where('action', 'disposal.book')->sole()->newValues());

        // Credit like a gift: balance, not drunk, not in the stock.
        $this->assertSame(600, Ledger::balance($this->user));
        $this->assertSame($stock, BeerStock::current());
        $this->assertSame(0, Stats::forUser($this->user)['total_beers']);
        $this->assertSame(['count' => 1, 'beers' => 3], Disposals::countFor($this->user));

        $page = $this->actingAs($this->user)->get('/konto')->assertOk();
        $page->assertSee('data-testid="disposal-notice"', false)->assertSee('Leergut-Bonus: 3 Biere gutgeschrieben');
        $page->assertDontSee('data-testid="disposal"', false);
        $this->get('/konto/verlauf')->assertOk()->assertSee('Leergut-Bonus: 3 Biere à CHF 2.00')->assertSee('CHF 6.00');

        // The notice can be dismissed; other users never see it.
        $this->post("/konto/hinweis/{$tx->id}")->assertRedirect();
        $this->get('/konto')->assertDontSee('data-testid="disposal-notice"', false);
        $this->actingAs($this->user())->get('/konto')->assertDontSee('Leergut-Bonus');
    }

    public function test_page_view_catches_up_when_the_cron_does_not_run(): void
    {
        $this->enable();
        $this->report($this->user);
        $this->travel(11)->minutes();
        Cache::forget('disposals-catchup');

        $this->actingAs($this->user())->get('/statistik');
        $this->assertSame(Disposal::STATUS_BOOKED, Disposal::query()->sole()->status);
        $this->assertSame(450, Ledger::balance($this->user));
    }

    public function test_random_mode_draws_inside_the_bounds(): void
    {
        $this->enable([AppSettings::DISPOSAL_MODE => AppSettings::DISPOSAL_MODE_RANDOM, AppSettings::DISPOSAL_RANDOM_MAX => 1, AppSettings::DISPOSAL_LOCK_DAYS => 0, AppSettings::DISPOSAL_MIN_BEERS => 0]);
        Disposals::report($this->user, $this->user);
        $this->travel(11)->minutes();
        $this->assertSame(1, Disposals::bookDue());
        $this->assertSame(1, Disposal::query()->sole()->quantity);

        Setting::put(AppSettings::DISPOSAL_RANDOM_MAX, 5);
        $seen = [];
        for ($i = 0; $i < 25; $i++) {
            Disposals::report($this->user, $this->user);
            $this->travel(11)->minutes();
            $this->assertSame(1, Disposals::bookDue());
            $seen[] = Disposal::query()->latest('id')->first()->quantity;
        }
        $this->assertGreaterThanOrEqual(1, min($seen));
        $this->assertLessThanOrEqual(5, max($seen));
        $this->assertSame(array_sum($seen) + 1, Disposals::countFor($this->user)['beers']);
    }

    public function test_minimum_counts_beers_rounds_and_guest_sales_but_not_stock_corrections(): void
    {
        $this->enable([AppSettings::DISPOSAL_LOCK_DAYS => 0, AppSettings::DISPOSAL_MIN_BEERS => 24]);
        $this->report($this->user);
        $this->travel(11)->minutes();
        Disposals::bookDue();
        $other = $this->user();

        $this->travel(1)->minutes();
        $this->drink(10);
        $round = $this->user();
        Transaction::query()->create([
            'user_id' => $round->id, 'type' => Transaction::TYPE_ROUND, 'status' => Transaction::STATUS_CONFIRMED,
            'quantity' => 8, 'unit_price' => 150, 'amount' => -1200, 'created_by' => $round->id,
        ]);
        GuestSale::query()->create(['beers' => 5, 'unit_price' => 150, 'amount' => 750, 'method' => 'cash']);
        // Not counted: breakage, pending/gift rows.
        BeerStock::correct(BeerStock::current() - 50, 'Bruch', $this->kassier());
        $this->assertSame(23, Disposals::beersSince(Disposals::lastActive()->reported_at));

        $this->actingAs($other)->get('/konto')->assertDontSee('data-testid="disposal"', false);
        $this->report($other)->assertSessionHas('error', __('disposal.blocked'));
        $this->assertSame('minimum', Disposals::blockReason());

        $this->drink(1);
        $this->assertNull(Disposals::blockReason());
        $this->report($other)->assertSessionHas('status', __('disposal.reported'));
    }

    public function test_switching_on_again_restarts(): void
    {
        $this->enable([AppSettings::DISPOSAL_LOCK_DAYS => 30, AppSettings::DISPOSAL_MIN_BEERS => 100]);
        $this->report($this->user);
        $this->travel(11)->minutes();
        Disposals::bookDue();
        $this->assertSame('locked', Disposals::blockReason());

        // Off and on again via the backend form: the earlier disposal no longer counts, no beers needed.
        $kassier = $this->kassier();
        $form = ['disposal_mode' => 'fixed', 'disposal_fixed' => 2, 'disposal_random_max' => 3, 'disposal_lock_days' => 30, 'disposal_min_beers' => 100, 'disposal_withdraw_minutes' => 10];
        $this->actingAs($kassier)->put('/admin/entsorgung/einstellungen', $form)->assertSessionHas('status');
        $this->assertFalse(AppSettings::disposalEnabled());
        $this->travel(1)->minutes();
        $this->put('/admin/entsorgung/einstellungen', $form + ['disposal_enabled' => '1'])->assertSessionHas('status');
        $this->assertTrue(AppSettings::disposalEnabled());
        $this->assertTrue(AppSettings::disposalEnabledAt()->equalTo(now()->startOfSecond()));
        $this->assertNull(Disposals::lastActive());

        $other = $this->user();
        $this->actingAs($other)->get('/konto')->assertSee('data-testid="disposal"', false);
        $this->report($other)->assertSessionHas('status', __('disposal.reported'));
    }

    public function test_user_with_own_pending_disposal_cannot_report_again(): void
    {
        $this->enable([AppSettings::DISPOSAL_LOCK_DAYS => 0, AppSettings::DISPOSAL_MIN_BEERS => 0]);
        $this->report($this->user);
        $this->report($this->user)->assertSessionHas('error', __('disposal.blocked'));
        $this->assertSame(1, Disposal::query()->count());
        $this->assertFalse(Disposals::canReport($this->user));
    }
}

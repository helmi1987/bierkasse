<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\CashEntry;
use App\Models\Setting;
use App\Models\StockCorrection;
use App\Services\BeerStock;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setDefaultLimit(100000);
        Mail::fake();
    }

    public function test_purchase_requires_beer_count_and_old_rows_stay_valid(): void
    {
        $this->actingAs($this->kassier());
        $entry = ['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => '24.00', 'method' => 'cash', 'note' => 'Harass'];

        $this->post('/admin/kasse', $entry)->assertSessionHasErrors('beers');
        $this->post('/admin/kasse', $entry + ['beers' => '0'])->assertSessionHasErrors('beers');
        $this->post('/admin/kasse', $entry + ['beers' => '24'])->assertSessionHas('status');
        $this->post('/admin/kasse', ['type' => 'income', 'date' => '2026-10-01', 'amount' => '5', 'method' => 'cash', 'note' => 'x', 'beers' => '99'])->assertSessionHas('status');

        // Older purchase without a count.
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-01-01', 'amount' => -1000, 'method' => 'cash', 'note' => 'alt']);

        $this->assertSame(24, BeerStock::current());
        $this->assertNull(CashEntry::query()->where('type', 'income')->value('beers'));
        $this->get('/admin/kasse')->assertOk()->assertSee('24 Biere');
    }

    public function test_stock_computation_with_undo_and_correction(): void
    {
        $kassier = $this->kassier();
        $this->purchase(30);
        $user = $this->user();

        $tx = Ledger::consume($user, 3);
        Ledger::consume($user, 2);
        $this->assertSame(25, BeerStock::current());

        Ledger::undo($tx, $user);
        $this->assertSame(28, BeerStock::current());

        $correction = BeerStock::correct(26, 'Inventur', $kassier);
        $this->assertSame(-2, $correction->delta);
        $this->assertSame(26, BeerStock::current());

        // Consumption is never blocked; the stock may become negative.
        foreach ([10, 10, 10] as $n) {
            Ledger::consume($user, $n);
        }
        $this->assertSame(-4, BeerStock::current());

        $this->actingAs($kassier)->post('/admin/lager/korrektur', ['counted' => '10', 'note' => 'gezählt'])->assertSessionHas('status');
        $this->assertSame(10, BeerStock::current());
        $this->assertDatabaseHas('stock_corrections', ['delta' => 14, 'counted' => 10, 'note' => 'gezählt', 'created_by' => $kassier->id]);
        $this->get('/admin')->assertOk()->assertSee('Lagerbestand')->assertSee('10 Biere')->assertSee('Bierlager tief');
    }

    public function test_single_mail_when_crossing_and_rearm_after_restock(): void
    {
        $this->kassier(['email' => 'kassier@example.org']);
        $this->purchase(22);
        $user = $this->user();

        Ledger::consume($user, 2); // 20: not below
        Mail::assertNothingSent();

        Ledger::consume($user, 1); // 19: crossing
        Mail::assertSent(KassierNotice::class, 1);
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => str_contains($m->noticeSubject, '19 Biere'));

        Ledger::consume($user, 5); // still below: no new mail
        $this->purchase(1); // 15: still below, stays armed off
        Ledger::consume($user, 1);
        Mail::assertSent(KassierNotice::class, 1);

        $this->purchase(10); // 24: back above -> re-armed
        $this->assertFalse(AppSettings::stockLowWarned());
        Ledger::consume($user, 5); // 19: crossing again
        Mail::assertSent(KassierNotice::class, 2);
    }

    public function test_undo_rearms_the_warning(): void
    {
        $this->kassier(['email' => 'kassier@example.org']);
        $this->purchase(20);
        $user = $this->user();

        $tx = Ledger::consume($user, 1);
        $this->assertTrue(AppSettings::stockLowWarned());
        Ledger::undo($tx, $user);
        $this->assertFalse(AppSettings::stockLowWarned());
    }

    public function test_minimum_zero_disables_warning(): void
    {
        $this->kassier(['email' => 'kassier@example.org']);
        Setting::put(AppSettings::STOCK_MINIMUM, 0);
        $this->purchase(5);

        Ledger::consume($this->user(), 10);
        Mail::assertNothingSent();
        $this->assertFalse(BeerStock::isLow());
        $this->actingAs($this->kassier())->get('/admin')->assertOk()->assertDontSee('Bierlager tief');
    }

    public function test_kassier_edits_minimum_and_user_is_forbidden(): void
    {
        $this->actingAs($this->user())->get('/admin/lager')->assertForbidden();
        $this->put('/admin/lager/mindestbestand', ['minimum' => '5'])->assertForbidden();
        $this->post('/admin/lager/korrektur', ['counted' => '5'])->assertForbidden();

        $this->assertSame(20, BeerStock::minimum());
        $this->actingAs($this->kassier())->get('/admin/lager')->assertOk()->assertSee('Mindestbestand');
        $this->put('/admin/lager/mindestbestand', ['minimum' => '12'])->assertSessionHas('status');
        $this->assertSame(12, AppSettings::stockMinimum());
        $this->put('/admin/lager/mindestbestand', ['minimum' => '-1'])->assertSessionHasErrors('minimum');
        $this->actingAs($this->admin())->put('/admin/lager/mindestbestand', ['minimum' => '0'])->assertSessionHas('status');
        $this->assertSame(0, AppSettings::stockMinimum());
    }

    public function test_dashboard_shows_stock_once_tracked(): void
    {
        $user = $this->user();
        $this->actingAs($user)->get('/konto')->assertOk()->assertDontSee('Im Lager')->assertDontSee('Lager leer');

        $this->purchase(24);
        $this->actingAs($user)->get('/konto')->assertSee('Im Lager: noch 24 Biere');

        StockCorrection::query()->create(['delta' => -24, 'counted' => 0, 'created_by' => $this->kassier()->id]);
        $this->actingAs($user)->get('/konto')->assertSee('Lager leer');
    }

    private function purchase(int $beers): void
    {
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -$beers * 100, 'beers' => $beers, 'method' => 'cash', 'note' => 'Kauf']);
        BeerStock::rearm();
    }
}

<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashEntry;
use App\Models\GuestSale;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\CashBook;
use App\Services\Gifts;
use App\Services\GuestSales;
use App\Services\Ledger;
use App\Services\LedgerException;
use App\Services\Stats;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Bier verschenken": beers as CHF credit for colleagues, paid by the giver.
 */
class GiftTest extends TestCase
{
    use RefreshDatabase;

    private User $giver;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -3000, 'beers' => 30, 'method' => 'cash', 'note' => 'Kauf']);
        $this->giver = $this->user(['first_name' => 'Gabi', 'last_name' => 'Geber']);
        Ledger::bookDeposit($this->giver, 3000, 'cash', $this->kassier());
    }

    public function test_gift_moves_credit_without_touching_stock_or_cash(): void
    {
        $anna = $this->user(['first_name' => 'Anna']);
        $beat = $this->user(['first_name' => 'Beat']);
        $cash = CashBook::balance();

        $this->actingAs($this->giver)->post('/konto/verschenken', $this->tokened('gift', [
            'gift_recipients' => [$anna->id, $beat->id], 'gift_quantity' => 2, 'gift_message' => 'Danke!',
        ]))->assertSessionHas('status', '4 Biere verschenkt (CHF 6.00).');

        $this->assertSame(3000 - 600, Ledger::balance($this->giver));
        $this->assertSame(300, Ledger::balance($anna));
        $this->assertSame(300, Ledger::balance($beat));
        $this->assertSame(30, BeerStock::current());
        $this->assertSame($cash, CashBook::balance());

        $this->actingAs($anna)->get('/konto')->assertOk()->assertSee('Gabi Geber hat dir 2 Biere geschenkt – Danke!');
        $stats = Stats::forUser($this->giver);
        $this->assertSame([4, 0, 600], [$stats['gifted'], $stats['total_beers'], $stats['total_amount']]);
        $this->assertSame([2, 0], [Stats::forUser($anna)['gift_received'], Stats::forUser($anna)['total_beers']]);
    }

    public function test_limit_self_and_inactive_are_refused(): void
    {
        $anna = $this->user();
        $poor = $this->user();
        $this->expectLedgerError(fn () => Ledger::giveBeers($poor, [$anna->id], 1), __('ledger.limit_reached'));
        $this->expectLedgerError(fn () => Ledger::giveBeers($this->giver, [$this->giver->id], 1), __('round.recipient_invalid'));
        $anna->update(['status' => User::STATUS_LEFT]);
        $this->expectLedgerError(fn () => Ledger::giveBeers($this->giver, [$anna->id], 1), __('round.recipient_invalid'));
        $this->assertSame(0, Transaction::query()->where('type', Transaction::TYPE_GIFT)->count());
    }

    public function test_undo_within_configured_minutes(): void
    {
        $anna = $this->user();
        $tx = Ledger::giveBeers($this->giver, [$anna->id], 3);
        $this->actingAs($this->giver)->post(route('account.undo', $tx))->assertSessionHas('status', __('gift.undone'));
        $this->assertSame(0, Ledger::balance($anna));
        $this->assertSame(3000, Ledger::balance($this->giver));

        $tx = Ledger::giveBeers($this->giver, [$anna->id], 1);
        $this->travel(6)->minutes();
        $this->actingAs($this->giver)->post(route('account.undo', $tx))->assertSessionHas('error');
        $this->actingAs($anna)->post(route('account.undo', $tx->giftParts()->first()))->assertSessionHas('error');

        Setting::put(AppSettings::UNDO_MINUTES, ['gift' => 10]);
        $this->actingAs($this->giver)->post(route('account.undo', $tx))->assertSessionHas('status');
    }

    public function test_undo_ends_once_a_recipient_booked_a_beer(): void
    {
        $anna = $this->user();
        $beat = $this->user();
        $tx = Ledger::giveBeers($this->giver, [$anna->id, $beat->id], 1);
        Ledger::bookDeposit($beat, 1000, 'cash', $this->kassier());
        $this->assertTrue($tx->canBeUndoneBy($this->giver));

        Ledger::consume($beat, 1);
        $this->assertFalse($tx->fresh()->canBeUndoneBy($this->giver));
        $this->actingAs($this->giver)->get('/konto')->assertOk()->assertDontSee(route('account.undo', $tx));
        $this->actingAs($this->giver)->post(route('account.undo', $tx))->assertSessionHas('error');
        $this->assertSame(150, Ledger::balance($anna));
    }

    public function test_undo_blocked_once_a_recipient_gave_beers_away_or_requested_payout(): void
    {
        $anna = $this->user();
        $beat = $this->user();
        $cleo = $this->user();
        $tx = Ledger::giveBeers($this->giver, [$anna->id, $beat->id, $cleo->id], 2);
        $this->assertTrue($tx->canBeUndoneBy($this->giver));

        // A recipient passes on a gift.
        Ledger::giveBeers($anna, [$cleo->id], 1);
        $this->assertFalse($tx->fresh()->canBeUndoneBy($this->giver));
        $this->actingAs($this->giver)->get('/konto')->assertOk()->assertDontSee(route('account.undo', $tx));
        $this->actingAs($this->giver)->post(route('account.undo', $tx))->assertSessionHas('error');
        $this->assertSame(1, Transaction::query()->where('type', Transaction::TYPE_GIFT)->whereNull('parent_id')->where('user_id', $this->giver->id)->count());

        // A pending payout of another recipient also blocks a gift (own gift, fresh recipients).
        $tx2 = Ledger::giveBeers($this->giver, [$beat->id], 1);
        $this->assertTrue($tx2->canBeUndoneBy($this->giver));
        Ledger::requestPayout($beat);
        $this->assertFalse($tx2->fresh()->canBeUndoneBy($this->giver));
        $this->expectLedgerError(fn () => Ledger::undo($tx2, $this->giver), __('ledger.undo_expired'));
        $this->assertSame(0, AuditLog::query()->where('action', 'gift.undo')->count());
    }

    public function test_undo_stays_possible_when_a_recipient_only_got_more_credit(): void
    {
        $anna = $this->user();
        $tx = Ledger::giveBeers($this->giver, [$anna->id], 1);
        Ledger::bookDeposit($anna, 1000, 'cash', $this->kassier());
        $this->assertTrue($tx->fresh()->canBeUndoneBy($this->giver));
    }

    public function test_second_undo_fails_without_second_audit_entry_or_rearm(): void
    {
        $anna = $this->user();
        $gift = Ledger::giveBeers($this->giver, [$anna->id], 1);
        $stale = Transaction::query()->find($gift->id);
        Ledger::undo($gift, $this->giver);
        $this->expectLedgerError(fn () => Ledger::undo($stale, $this->giver), __('ledger.undo_expired'));
        $this->assertSame(1, AuditLog::query()->where('action', 'gift.undo')->count());

        $tx = Ledger::consume($this->giver, 1);
        $stale = Transaction::query()->find($tx->id);
        Ledger::undo($tx, $this->giver);
        $this->expectLedgerError(fn () => Ledger::undo($stale, $this->giver), __('ledger.undo_expired'));
        $this->assertSame(1, AuditLog::query()->where('action', 'consume.undo')->count());

        $sale = GuestSales::book(2, 'cash', null, $this->giver);
        $stale = GuestSale::query()->find($sale->id);
        GuestSales::undo($sale, $this->giver);
        $this->expectLedgerError(fn () => GuestSales::undo($stale, $this->giver), __('ledger.undo_expired'));
        $this->assertSame(1, AuditLog::query()->where('action', 'guest_sale.undo')->count());
    }

    public function test_admin_sets_undo_minutes_per_kind(): void
    {
        Setting::put(AppSettings::GIFT_UNDO_MINUTES, 7);
        $this->assertSame([10, 10, 10, 7], array_map(fn ($k) => AppSettings::undoMinutes($k), ['consume', 'round', 'guest', 'gift']));
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/einstellungen')->assertOk()->assertSee('name="undo_minutes[gift]" value="7"', false);

        $form = ['beer_price' => '1.50', 'default_limit' => '0', 'registration_mode' => 'invite', 'mail_transport' => 'sendmail'];
        $this->actingAs($admin)->put('/admin/einstellungen', $form + ['undo_minutes' => ['consume' => 3, 'round' => 0, 'guest' => 15, 'gift' => 61]])
            ->assertSessionHasErrors('undo_minutes.gift');
        $this->actingAs($admin)->put('/admin/einstellungen', $form + ['undo_minutes' => ['consume' => 3, 'round' => 0, 'guest' => 15, 'gift' => 60]])
            ->assertSessionHas('status');
        $this->assertSame([3, 0, 15, 60], array_map(fn ($k) => AppSettings::undoMinutes($k), ['consume', 'round', 'guest', 'gift']));

        $tx = Ledger::consume($this->giver, 1);
        $this->travel(4)->minutes();
        $this->assertFalse($tx->canBeUndoneBy($this->giver));
        $this->actingAs($this->kassier())->put('/admin/einstellungen', $form)->assertForbidden();
    }

    public function test_backend_switch_and_list(): void
    {
        $anna = $this->user(['first_name' => 'Anna']);
        Ledger::giveBeers($this->giver, [$anna->id], 2);
        $kassier = $this->kassier();
        $this->actingAs($kassier)->get('/admin/verschenkt')->assertRedirect(route('backend.bookings', ['tab' => 'geschenke']));
        $this->get('/admin/buchungen?tab=geschenke')->assertOk()->assertSee('an Anna', false);
        $this->actingAs($this->user())->get('/admin/buchungen?tab=geschenke')->assertForbidden();

        // Switch: admins only (Admin > Einstellungen > Funktionen) since 1.20.0.
        $this->actingAs($kassier)->put('/admin/einstellungen/funktionen', $this->featureForm(['gifts_enabled' => '0']))->assertForbidden();
        $this->assertTrue(AppSettings::giftsAllowed());
        $this->actingAs($this->admin())->put('/admin/einstellungen/funktionen', $this->featureForm(['gifts_enabled' => '0']))->assertSessionHas('status');
        $this->assertFalse(AppSettings::giftsAllowed());
        $this->actingAs($this->giver)->get('/konto')->assertOk()->assertDontSee('data-testid="gift"', false);
        $this->actingAs($this->giver)->post('/konto/verschenken', $this->tokened('gift', ['gift_recipients' => [$anna->id], 'gift_quantity' => 1]))->assertNotFound();
    }

    private function expectLedgerError(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->fail('LedgerException expected');
        } catch (LedgerException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_authoritative_undo_check_is_a_locking_read(): void
    {
        $anna = $this->user();
        $tx = Ledger::giveBeers($this->giver, [$anna->id], 1);

        $this->assertNull(Gifts::recipientsBookedQuery($tx)->getQuery()->lock);
        $this->assertTrue(Gifts::recipientsBookedQuery($tx, true)->getQuery()->lock);
        $this->assertFalse(Gifts::recipientsBookedSince($tx, true));

        Ledger::bookDeposit($anna, 1000, 'cash', $this->kassier());
        Ledger::consume($anna, 1);
        $this->assertTrue(Gifts::recipientsBookedSince($tx, true));
        $this->assertFalse($tx->fresh()->canBeUndoneBy($this->giver, true));
    }
}

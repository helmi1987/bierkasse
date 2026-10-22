<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\CashBook;
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

        Setting::put(AppSettings::GIFT_UNDO_MINUTES, 10);
        $this->actingAs($this->giver)->post(route('account.undo', $tx))->assertSessionHas('status');
    }

    public function test_backend_switch_and_list(): void
    {
        $anna = $this->user(['first_name' => 'Anna']);
        Ledger::giveBeers($this->giver, [$anna->id], 2);
        $kassier = $this->kassier();
        $this->actingAs($kassier)->get('/backend/verschenkt')->assertOk()->assertSee('an Anna', false);
        $this->actingAs($this->user())->get('/backend/verschenkt')->assertForbidden();

        $this->actingAs($kassier)->put('/backend/verschenkt', ['gift_undo_minutes' => 0])->assertSessionHas('status');
        $this->assertFalse(AppSettings::giftsAllowed());
        $this->assertSame(0, AppSettings::giftUndoMinutes());
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
}

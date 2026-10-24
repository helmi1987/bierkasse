<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\CashBook;
use App\Services\Ledger;
use App\Services\LedgerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decision 2026-10-23: reported deposits (and carry-overs) not yet confirmed count for the user
 * (displayed balance and limit checks); backend accounting stays confirmed-only.
 */
class PendingCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_deposit_is_part_of_the_shown_balance_as_one_number(): void
    {
        $this->setDefaultLimit(0);
        $user = $this->user();
        Ledger::reportDeposit($user, 3000, 'twint');
        Ledger::reportDeposit($user, 100, 'cash');

        $this->assertSame(0, $user->balance());
        $this->assertSame(3100, $user->availableBalance());

        $this->actingAs($user)->get('/konto')->assertOk()
            ->assertSee('20 Biere')->assertSee('CHF 31.00')
            ->assertDontSee('ausstehend')->assertDontSee('data-testid="pending-deposit"', false)
            // The own report stays editable/withdrawable in the list below the deposit form.
            ->assertSee(__('account.deposit_cancel'));
        $this->get('/konto/verlauf')->assertOk()->assertSee('CHF 31.00');
    }

    public function test_beers_rounds_and_gifts_can_be_booked_against_a_pending_deposit(): void
    {
        $this->setDefaultLimit(0);
        $user = $this->user();
        $colleague = $this->user();
        Ledger::reportDeposit($user, 900, 'cash');
        $this->actingAs($user);

        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 2]))->assertSessionHas('status');
        $this->post('/konto/runde', $this->tokened('round', ['recipients' => [$colleague->id]]))->assertSessionHas('status');
        $this->post('/konto/verschenken', $this->tokened('gift', ['gift_recipients' => [$colleague->id], 'gift_quantity' => 1]))->assertSessionHas('status');
        $this->assertSame(900 - 4 * 150, $user->availableBalance());

        // The limit still applies to the available balance: 3.00 left, 3 beers (4.50) are too many.
        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 3]))->assertSessionHas('error');
        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 2]))->assertSessionHas('status');
        $this->assertSame(0, $user->availableBalance());
    }

    public function test_withdrawn_or_rejected_deposit_leaves_the_user_negative_beyond_the_limit(): void
    {
        $this->setDefaultLimit(0);
        $user = $this->user();
        $kassier = $this->kassier();
        $tx = Ledger::reportDeposit($user, 300, 'cash');
        Ledger::consume($user, 2);

        $this->actingAs($user)->delete("/konto/einzahlung/{$tx->id}")->assertSessionHas('status');
        $this->assertSame(-300, $user->availableBalance());
        $this->get('/konto')->assertOk()->assertSee('2 Biere offen')->assertSee('CHF -3.00');
        // No further beers below the limit; nothing else is blocked automatically.
        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]))->assertSessionHas('error');

        $second = Ledger::reportDeposit($user, 450, 'twint');
        Ledger::consume($user, 1);
        Ledger::rejectDeposit($second, $kassier);
        $this->assertSame(-450, $user->availableBalance());
        $this->assertSame(-450, $user->balance());
    }

    public function test_backend_totals_count_confirmed_money_only(): void
    {
        $this->setDefaultLimit(0);
        $user = $this->user();
        $kassier = $this->kassier();
        Ledger::bookDeposit($user, 1000, 'cash', $kassier);
        $before = ['credit' => Ledger::totalCredit(), 'cash' => CashBook::balance(), 'overview' => CashBook::overview()];

        Ledger::reportDeposit($user, 3000, 'twint');
        Ledger::reportCarryover($user, 500);
        $this->assertSame($before['credit'], Ledger::totalCredit());
        $this->assertSame($before['cash'], CashBook::balance());
        $this->assertSame($before['overview'], CashBook::overview());
        $this->assertSame(1000, Ledger::balances()[$user->id]);
        $this->assertSame(1000, $user->balance());
        $this->assertSame(4500, $user->availableBalance());

        // Kassier views: user detail shows the confirmed balance.
        $this->actingAs($kassier)->get("/backend/benutzer/{$user->id}")->assertOk()->assertSee('CHF 10.00');
    }

    public function test_pending_carryover_counts_like_a_pending_deposit(): void
    {
        $this->setDefaultLimit(0);
        $user = $this->user();
        Ledger::reportCarryover($user, 300);

        $this->actingAs($user)->post('/konto/bier', $this->tokened('consume', ['quantity' => 2]))->assertSessionHas('status');
        $this->assertSame(0, $user->availableBalance());
        $this->assertSame(-300, $user->balance());
    }

    public function test_pending_payout_still_blocks_and_does_not_count(): void
    {
        $user = $this->user();
        Ledger::bookDeposit($user, 600, 'cash', $this->kassier());
        Ledger::requestPayout($user);

        $this->assertSame(600, $user->availableBalance());
        $this->expectException(LedgerException::class);
        Ledger::consume($user, 1);
    }

    public function test_available_scope_ignores_rejected_and_pending_payouts(): void
    {
        $user = $this->user();
        $kassier = $this->kassier();
        $rejected = Ledger::reportDeposit($user, 700, 'cash');
        Ledger::rejectDeposit($rejected, $kassier);
        $this->assertSame(0, $user->availableBalance());
        $this->assertSame(0, Transaction::query()->where('user_id', $user->id)->available()->count());
    }
}

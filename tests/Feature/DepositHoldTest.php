<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ledger;
use App\Services\LedgerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pending deposits on hold after a rejected deposit (decision 2026-10-24).
 */
class DepositHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setPrice(150);
        $this->setDefaultLimit(300);
    }

    /** User reports a (fake) deposit, drinks on it, the kassier rejects it: confirmed balance -600. */
    private function rejectedUser(): User
    {
        $user = $this->user();
        $fake = Ledger::reportDeposit($user, 2000, 'twint');
        Ledger::consume($user, 4);
        $this->travel(1)->minutes();
        Ledger::rejectDeposit($fake, $this->kassier());
        $this->travel(1)->minutes();

        return $user;
    }

    public function test_new_report_after_rejection_does_not_count_for_the_limit(): void
    {
        $user = $this->rejectedUser();
        $this->assertTrue(Ledger::depositsOnHold($user));

        $this->actingAs($user)->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => '20', 'method' => 'twint']))
            ->assertSessionHas('status', __('ledger.deposit_reported_on_hold'));
        $this->assertSame(-600, Ledger::availableBalance($user));
        $this->get('/konto')->assertOk()->assertSee('CHF -6.00')->assertSee(__('account.deposit_on_hold'));

        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]))
            ->assertSessionHas('error', __('ledger.limit_reached_on_hold'));
        $this->assertSame(-600, $user->balance());
    }

    public function test_confirmed_deposit_after_rejection_releases_the_hold(): void
    {
        $user = $this->rejectedUser();
        $pending = Ledger::reportDeposit($user, 2000, 'cash');
        $this->assertTrue(Ledger::depositsOnHold($user));

        Ledger::confirmDeposit($pending, $this->kassier());
        $this->travel(1)->minutes();
        Ledger::consume($user, 10);
        $this->assertSame(-100, $user->balance());
        // Balance went below 0 again, but the rejection is followed by a confirmed deposit.
        Ledger::reportDeposit($user, 1000, 'twint');
        $this->assertFalse(Ledger::depositsOnHold($user));
        $this->assertSame(900, Ledger::availableBalance($user));
    }

    public function test_deposit_booked_by_kassier_releases_the_hold(): void
    {
        $user = $this->rejectedUser();
        Ledger::reportDeposit($user, 2000, 'twint');
        Ledger::bookDeposit($user, 100, 'cash', $this->kassier());

        $this->assertFalse(Ledger::depositsOnHold($user));
        $this->assertSame(-500 + 2000, Ledger::availableBalance($user));
        Ledger::consume($user, 5);
    }

    public function test_no_hold_when_confirmed_balance_is_not_negative(): void
    {
        $user = $this->rejectedUser();
        // Settled in person: the kassier books a correction, no new deposit.
        Ledger::correction($user, 600, 'paid in person', $this->kassier());
        Ledger::reportDeposit($user, 2000, 'twint');

        $this->assertFalse(Ledger::depositsOnHold($user));
        $this->assertSame(2000, Ledger::availableBalance($user));
        Ledger::consume($user, 10);
        $this->assertSame(-1500, $user->balance());
        // Now below 0 with the old rejection still the latest: the remaining pending deposit is on hold.
        $this->assertTrue(Ledger::depositsOnHold($user));
    }

    public function test_pending_carryover_still_counts_while_on_hold(): void
    {
        $user = $this->rejectedUser();
        Ledger::reportCarryover($user, 1000);
        Ledger::reportDeposit($user, 2000, 'twint');

        $this->assertTrue(Ledger::depositsOnHold($user));
        $this->assertSame(-600 + 1000, Ledger::availableBalance($user));
        Ledger::consume($user, 4);
        $this->assertSame(-200, Ledger::availableBalance($user));
    }

    public function test_round_and_gift_use_the_same_hold(): void
    {
        $user = $this->rejectedUser();
        $anna = $this->user();
        Ledger::reportDeposit($user, 5000, 'twint');

        foreach ([fn () => Ledger::giveBeers($user, [$anna->id], 1), fn () => Ledger::giveRound($user, [$anna->id], 0)] as $fn) {
            try {
                $fn();
                $this->fail('LedgerException expected');
            } catch (LedgerException $e) {
                $this->assertSame(__('ledger.limit_reached_on_hold'), $e->getMessage());
            }
        }
    }

    public function test_without_rejection_pending_deposits_count_as_before(): void
    {
        $user = $this->user();
        Ledger::reportDeposit($user, 2000, 'twint');
        Ledger::consume($user, 10);

        $this->assertFalse(Ledger::depositsOnHold($user));
        $this->assertSame(500, Ledger::availableBalance($user));

        Ledger::consume($user, 5);
        try {
            Ledger::consume($user, 1);
            $this->fail('LedgerException expected');
        } catch (LedgerException $e) {
            $this->assertSame(__('ledger.limit_reached'), $e->getMessage());
        }
        $this->actingAs($user)->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => '5', 'method' => 'cash']))
            ->assertSessionHas('status', __('ledger.deposit_reported'));
    }
}

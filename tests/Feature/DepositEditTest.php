<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\Ledger;
use App\Services\LedgerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DepositEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_edits_own_pending_deposit(): void
    {
        $user = $this->user();
        $tx = Ledger::reportDeposit($user, 3000, 'twint');
        Mail::fake();
        $this->actingAs($user);

        $this->get('/konto')->assertOk()->assertSee(route('account.deposit.update', $tx))->assertSee('Nicht bezahlt – zurückziehen');

        $this->put("/konto/einzahlung/{$tx->id}", ['amount' => '20.00', 'method' => 'cash'])->assertSessionHas('status');
        $tx->refresh();
        $this->assertSame(2000, $tx->amount);
        $this->assertSame('cash', $tx->method);
        $this->assertTrue($tx->isPending());
        $this->assertNotNull($tx->edited_at);
        $this->assertSame(0, $user->balance());
        Mail::assertNothingSent();

        $this->put("/konto/einzahlung/{$tx->id}", ['amount' => '0', 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->put("/konto/einzahlung/{$tx->id}", ['amount' => '10', 'method' => 'gold'])->assertSessionHasErrors('method');

        // Kassier sees the new values marked as changed.
        $this->actingAs($this->kassier())->get('/backend')->assertOk()->assertSee('CHF 20.00')->assertSee('geändert');
    }

    public function test_user_withdraws_own_pending_deposit(): void
    {
        $user = $this->user();
        $tx = Ledger::reportDeposit($user, 3000, 'cash');

        $this->actingAs($user)->delete("/konto/einzahlung/{$tx->id}")->assertSessionHas('status');
        $this->assertNull(Transaction::query()->find($tx->id));
        $this->get('/konto')->assertOk()->assertDontSee('wartet auf Kassier');
    }

    public function test_other_users_deposit_is_not_found(): void
    {
        $tx = Ledger::reportDeposit($this->user(), 3000, 'cash');
        $this->actingAs($this->user());

        $this->put("/konto/einzahlung/{$tx->id}", ['amount' => '1', 'method' => 'cash'])->assertNotFound();
        $this->delete("/konto/einzahlung/{$tx->id}")->assertNotFound();
        $this->assertSame(3000, $tx->fresh()->amount);

        // Only deposits: a consumption cannot be "withdrawn".
        $me = $this->user();
        $this->setDefaultLimit(1000);
        $beer = Ledger::consume($me, 1);
        $this->actingAs($me)->delete("/konto/einzahlung/{$beer->id}")->assertNotFound();
    }

    public function test_confirmed_or_rejected_deposit_cannot_be_changed(): void
    {
        $user = $this->user();
        $kassier = $this->kassier();
        $confirmed = Ledger::reportDeposit($user, 3000, 'cash');
        Ledger::confirmDeposit($confirmed, $kassier);
        $rejected = Ledger::reportDeposit($user, 1000, 'cash');
        Ledger::rejectDeposit($rejected, $kassier);
        $this->actingAs($user);

        $this->put("/konto/einzahlung/{$confirmed->id}", ['amount' => '1', 'method' => 'cash'])
            ->assertSessionHas('error', __('ledger.deposit_already_confirmed'));
        $this->delete("/konto/einzahlung/{$confirmed->id}")->assertSessionHas('error', __('ledger.deposit_already_confirmed'));
        $this->delete("/konto/einzahlung/{$rejected->id}")->assertSessionHas('error', __('ledger.not_pending'));
        $this->assertSame(3000, $confirmed->fresh()->amount);
        $this->assertNotNull($rejected->fresh());
        $this->assertSame(3000, $user->balance());
    }

    public function test_race_confirm_after_cancel_fails_cleanly(): void
    {
        $user = $this->user();
        $kassier = $this->kassier();
        $tx = Ledger::reportDeposit($user, 3000, 'cash');
        $stale = Transaction::query()->findOrFail($tx->id);

        Ledger::cancelDeposit($tx, $user);

        // Kassier still has the old list open.
        try {
            Ledger::confirmDeposit($stale, $kassier);
            $this->fail('confirm of a withdrawn report must fail');
        } catch (LedgerException $e) {
            $this->assertSame(__('ledger.not_pending'), $e->getMessage());
        }
        $this->assertSame(0, $user->balance());

        // Via HTTP: clean error message, no 500.
        $this->actingAs($kassier)->post("/backend/einzahlungen/{$tx->id}/bestaetigen")->assertNotFound();

        // The other way round: confirmed first, then the user's withdrawal fails.
        $tx2 = Ledger::reportDeposit($user, 1500, 'cash');
        $stale2 = Transaction::query()->findOrFail($tx2->id);
        Ledger::confirmDeposit($tx2, $kassier);
        $this->expectException(LedgerException::class);
        Ledger::cancelDeposit($stale2, $user);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_payout_request_and_confirmation_closes_account(): void
    {
        $this->setDefaultLimit(1000);
        $user = $this->user();
        $kassier = $this->kassier();
        Ledger::bookDeposit($user, 2000, 'cash', $kassier);
        Ledger::consume($user, 2);

        $this->actingAs($user)->post('/konto/verlassen', ['choice' => 'payout', 'confirm' => '1'])
            ->assertRedirect(route('account.dashboard'));
        $payout = Transaction::query()->where('type', 'payout')->firstOrFail();
        $this->assertSame('pending', $payout->status);
        $this->assertTrue($user->fresh()->isActive());

        // While the payout is pending no further beers.
        $this->post('/konto/bier', ['quantity' => 1])->assertSessionHas('error');

        $this->actingAs($kassier)->post("/backend/auszahlungen/{$payout->id}/bestaetigen", ['method' => 'cash'])->assertSessionHas('status');

        $payout->refresh();
        $this->assertSame('confirmed', $payout->status);
        $this->assertSame(-1700, $payout->amount);
        $this->assertSame(0, $user->balance());
        $this->assertSame(User::STATUS_LEFT, $user->fresh()->status);

        // Left accounts cannot log in anymore; history is kept.
        $this->post('/abmelden');
        $this->post('/anmelden', ['username' => $user->username, 'password' => 'secret-password'])->assertSessionHasErrors('username');
        $this->assertSame(3, Transaction::query()->where('user_id', $user->id)->count());
    }

    public function test_cancelled_payout_keeps_account_active(): void
    {
        $user = $this->user();
        Ledger::bookDeposit($user, 500, 'cash', $this->kassier());
        $payout = Ledger::requestPayout($user);

        $this->actingAs($this->kassier())->post("/backend/auszahlungen/{$payout->id}/ablehnen")->assertSessionHas('status');
        $this->assertSame('rejected', $payout->fresh()->status);
        $this->assertTrue($user->fresh()->isActive());
        $this->assertSame(500, $user->balance());
    }

    public function test_donation_closes_account_and_logs_out(): void
    {
        $user = $this->user();
        Ledger::bookDeposit($user, 800, 'twint', $this->kassier());

        $this->actingAs($user)->post('/konto/verlassen', ['choice' => 'donate', 'confirm' => '1'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(User::STATUS_LEFT, $user->fresh()->status);
        $this->assertSame(0, $user->balance());
        $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'type' => 'donation', 'amount' => -800, 'status' => 'confirmed']);
    }

    public function test_negative_balance_must_pay_first(): void
    {
        $this->setDefaultLimit(1000);
        $user = $this->user();
        Ledger::consume($user, 1);

        $this->actingAs($user)->get('/konto/verlassen')->assertOk()->assertSee('Bitte zuerst einzahlen');
        $this->post('/konto/verlassen', ['choice' => 'donate', 'confirm' => '1'])->assertSessionHas('error');
        $this->post('/konto/verlassen', ['choice' => 'payout', 'confirm' => '1'])->assertSessionHas('error');
        $this->assertTrue($user->fresh()->isActive());
        $this->assertSame(0, Transaction::query()->whereIn('type', ['payout', 'donation'])->count());
    }

    public function test_leaving_requires_confirmation(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/konto/verlassen', ['choice' => 'donate'])->assertSessionHasErrors('confirm');
        $this->assertTrue($user->fresh()->isActive());
    }
}

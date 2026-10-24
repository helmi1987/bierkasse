<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CashEntry;
use App\Models\RoundItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StornoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setDefaultLimit(5000);
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -3000, 'beers' => 30, 'method' => 'cash', 'note' => 'Kauf']);
    }

    private function storno(User $user, Transaction $tx, array $data = ['note' => 'Falsch gebucht'])
    {
        return $this->post(route('backend.users.storno', [$user, $tx]), $data);
    }

    public function test_consumption_storno_restores_balance_and_stock(): void
    {
        $user = $this->user();
        $tx = Ledger::consume($user, 2);
        $this->assertSame(28, BeerStock::current());
        $this->travel(2)->days();

        $this->actingAs($this->kassier());
        $this->get(route('backend.users.show', $user))->assertOk()->assertSee(route('backend.users.storno', [$user, $tx]));
        $this->storno($user, $tx)->assertSessionHas('status', __('ledger.storno_done'));

        $this->assertNull(Transaction::query()->find($tx->id));
        $this->assertSame(0, $user->balance());
        $this->assertSame(30, BeerStock::current());
        $audit = AuditLog::query()->where('action', 'consume.storno')->firstOrFail();
        $this->assertSame('Falsch gebucht', json_decode($audit->new_values, true)['note']);
        $this->assertSame(-300, json_decode($audit->old_values, true)['amount']);

        // Second storno: gone.
        $this->storno($user, $tx)->assertNotFound();
    }

    public function test_note_is_required(): void
    {
        $user = $this->user();
        $tx = Ledger::consume($user, 1);
        $this->actingAs($this->kassier());

        $this->storno($user, $tx, ['note' => ''])->assertSessionHasErrors('note');
        $this->assertNotNull(Transaction::query()->find($tx->id));
        $this->assertSame(29, BeerStock::current());
    }

    public function test_user_role_is_forbidden(): void
    {
        $user = $this->user();
        $tx = Ledger::consume($user, 1);
        $this->actingAs($user);

        $this->storno($user, $tx)->assertForbidden();
        $this->assertNotNull(Transaction::query()->find($tx->id));
    }

    public function test_round_storno_removes_items_and_restores_stock(): void
    {
        $giver = $this->user();
        $colleague = $this->user();
        $tx = Ledger::giveRound($giver, [$colleague->id], 2);
        $this->assertSame(27, BeerStock::current());
        $this->travel(1)->hours();

        $this->actingAs($this->admin());
        $this->storno($giver, $tx)->assertSessionHas('status');

        $this->assertSame(0, $giver->balance());
        $this->assertSame(30, BeerStock::current());
        $this->assertSame(0, RoundItem::query()->count());
        $old = json_decode(AuditLog::query()->where('action', 'round.storno')->firstOrFail()->old_values, true);
        $this->assertSame([$colleague->id, null], $old['recipients']);
    }

    public function test_gift_storno_reverses_recipients(): void
    {
        $giver = $this->user();
        $a = $this->user();
        $b = $this->user();
        $tx = Ledger::giveBeers($giver, [$a->id, $b->id], 2);
        $this->assertSame(300, $a->balance());
        $this->travel(1)->hours();

        $this->actingAs($this->kassier());
        $this->storno($giver, $tx)->assertSessionHas('status');

        $this->assertSame(0, $giver->balance());
        $this->assertSame(0, $a->balance());
        $this->assertSame(0, $b->balance());
        $this->assertSame(0, Transaction::query()->where('type', Transaction::TYPE_GIFT)->count());
        $this->assertSame(30, BeerStock::current());
    }

    public function test_gift_storno_blocked_once_recipient_spent(): void
    {
        $giver = $this->user();
        $a = $this->user();
        $tx = Ledger::giveBeers($giver, [$a->id], 2);
        Ledger::consume($a, 1);

        $this->actingAs($this->kassier());
        $this->storno($giver, $tx)->assertSessionHas('error', __('gift.storno_blocked'));
        $this->assertNotNull(Transaction::query()->find($tx->id));
        $this->assertSame(150, $a->balance());
    }

    public function test_only_beer_round_and_given_gift_and_not_for_left_accounts(): void
    {
        $user = $this->user();
        $kassier = $this->kassier();
        $deposit = Ledger::bookDeposit($user, 1000, 'cash', $kassier);
        $this->actingAs($kassier);

        $this->storno($user, $deposit)->assertSessionHas('error', __('ledger.storno_invalid'));
        $this->assertNotNull(Transaction::query()->find($deposit->id));

        // Recipient row of a gift: only the giver's row can be cancelled.
        $giver = $this->user();
        Ledger::giveBeers($giver, [$user->id], 1);
        $part = Transaction::query()->where('user_id', $user->id)->where('type', Transaction::TYPE_GIFT)->firstOrFail();
        $this->storno($user, $part)->assertSessionHas('error', __('ledger.storno_invalid'));

        // Booking of another user via this user's URL.
        $other = $this->user();
        $foreign = Ledger::consume($other, 1);
        $this->storno($user, $foreign)->assertNotFound();

        // Left account: nothing is booked any more.
        $beer = Ledger::consume($other, 1);
        $other->forceFill(['status' => User::STATUS_LEFT])->save();
        $this->storno($other, $beer)->assertSessionHas('error', __('ledger.user_left'));
    }

    public function test_storno_on_locked_account_works(): void
    {
        $user = $this->user();
        $tx = Ledger::consume($user, 1);
        $user->forceFill(['status' => User::STATUS_LOCKED])->save();
        $this->actingAs($this->kassier());

        $this->storno($user, $tx)->assertSessionHas('status');
        $this->assertSame(0, $user->balance());
    }
}

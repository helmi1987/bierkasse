<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Services\CashBook;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashBookTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_balance_computation(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(10000);
        $kassier = $this->kassier();
        $a = $this->user();
        $b = $this->user();
        $c = $this->user();
        $d = $this->user();

        Ledger::bookDeposit($a, 5000, 'cash', $kassier);           // cash +50
        Ledger::bookDeposit($b, 3000, 'twint', $kassier);          // twint +30
        Ledger::reportDeposit($c, 9900, 'cash');                   // pending: not counted
        Ledger::consume($a, 4);                                    // credit only
        Ledger::correction($b, -500, 'Fehler', $kassier);          // credit only

        // Leaving with payout (cash): -19.00 from the cash box
        Ledger::bookDeposit($d, 1900, 'cash', $kassier);           // cash +19
        Ledger::confirmPayout(Ledger::requestPayout($d), $kassier, 'cash'); // cash -19

        $this->actingAs($kassier);
        $this->post('/backend/kasse', ['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => '24.00', 'method' => 'cash', 'note' => 'Harass'])->assertSessionHas('status');
        $this->post('/backend/kasse', ['type' => 'income', 'date' => '2026-10-02', 'amount' => '10', 'method' => 'twint', 'note' => 'Apéro'])->assertSessionHas('status');
        $this->post('/backend/kasse', ['type' => 'adjustment', 'date' => '2026-10-03', 'amount' => '-1.50', 'method' => 'cash', 'note' => 'Zählung'])->assertSessionHas('status');
        $this->post('/backend/kasse', ['type' => 'expense_other', 'date' => '2026-10-03', 'amount' => '-5', 'method' => 'cash', 'note' => 'x'])->assertSessionHasErrors('amount');
        $this->post('/backend/kasse', ['type' => 'income', 'date' => '2026-10-03', 'amount' => '5', 'method' => 'cash', 'note' => ''])->assertSessionHasErrors('note');

        $this->assertSame([-2400, 1000, -150], CashEntry::query()->orderBy('id')->pluck('amount')->all());

        $cash = CashBook::balance();
        // cash: 50 + 19 - 19 - 24 - 1.50 = 24.50 ; twint: 30 + 10 = 40
        $this->assertSame(2450, $cash['cash']);
        $this->assertSame(4000, $cash['twint']);
        $this->assertSame(6450, $cash['total']);

        $overview = CashBook::overview();
        // credit: a 50 - 6 = 44 ; b 30 - 5 = 25 ; c 0 (pending) ; d 0 (paid out)
        $this->assertSame(6900, $overview['credit']);
        $this->assertSame(-450, $overview['surplus']);

        $this->get('/backend/kasse')->assertOk()->assertSee('CHF 64.50')->assertSee('Harass');
        $this->get('/backend')->assertOk()->assertSee('CHF 69.00')->assertSee('CHF -4.50');
    }

    public function test_donation_increases_surplus(): void
    {
        $kassier = $this->kassier();
        $user = $this->user();
        Ledger::bookDeposit($user, 1000, 'cash', $kassier);
        $this->assertSame(0, CashBook::overview()['surplus']);

        Ledger::donate($user);

        $overview = CashBook::overview();
        $this->assertSame(1000, $overview['cash']['total']);
        $this->assertSame(0, $overview['credit']);
        $this->assertSame(1000, $overview['surplus']);
    }

    public function test_kassier_deletes_entry(): void
    {
        $kassier = $this->kassier();
        $entry = CashEntry::query()->create(['type' => 'income', 'date' => now(), 'amount' => 500, 'method' => 'cash', 'note' => 'x', 'created_by' => $kassier->id]);

        $this->actingAs($kassier)->delete("/backend/kasse/{$entry->id}")->assertSessionHas('status');
        $this->assertSame(0, CashEntry::query()->count());
    }
}

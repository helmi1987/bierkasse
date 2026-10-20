<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Transaction;
use App\Services\Ledger;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumption_stores_price_and_keeps_it_after_price_change(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(1000);
        $user = $this->user();

        $this->actingAs($user)->post('/konto/bier', $this->tokened('consume', ['quantity' => 2]))->assertSessionHas('status');
        $this->setPrice(200);
        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]));

        $rows = Transaction::query()->where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame([150, 200], $rows->pluck('unit_price')->all());
        $this->assertSame([-300, -200], $rows->pluck('amount')->all());
        $this->assertSame(-500, $user->balance());

        // Open beers keep their original price.
        $open = Ledger::openItems($user);
        $this->assertSame(500, $open['total']);
        $this->assertSame([
            ['unit_price' => 150, 'quantity' => 2, 'amount' => 300],
            ['unit_price' => 200, 'quantity' => 1, 'amount' => 200],
        ], $open['beers']);

        $this->get('/konto')->assertOk()->assertSee('Offen: CHF 5.00')->assertSee('CHF -5.00');
    }

    public function test_partial_payment_pays_oldest_beers_first(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(1000);
        $user = $this->user();
        Ledger::consume($user, 2);
        $this->travel(1)->minutes();
        $this->setPrice(200);
        Ledger::consume($user, 2);
        $this->travel(1)->minutes();
        Ledger::bookDeposit($user, 300, 'cash', $this->kassier());

        $open = Ledger::openItems($user);
        $this->assertSame(400, $open['total']);
        $this->assertSame([['unit_price' => 200, 'quantity' => 2, 'amount' => 400]], $open['beers']);
    }

    public function test_default_overdraft_limit(): void
    {
        $this->setPrice(150);
        $user = $this->user();

        // Default limit 0: no minus allowed.
        $this->actingAs($user)->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]))->assertSessionHas('error', 'Bitte zuerst einzahlen');
        $this->assertSame(0, Transaction::query()->count());

        $this->setDefaultLimit(300);
        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 2]))->assertSessionHas('status');
        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]))->assertSessionHas('error', 'Bitte zuerst einzahlen');
        $this->assertSame(-300, $user->balance());
    }

    public function test_individual_overdraft_limit_overrides_default(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(1000);
        $user = $this->user(['overdraft_limit' => 0]);
        Ledger::bookDeposit($user, 150, 'cash', $this->kassier());

        $this->actingAs($user)->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]))->assertSessionHas('status');
        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 1]))->assertSessionHas('error', 'Bitte zuerst einzahlen');
        $this->assertSame(0, $user->balance());
    }

    public function test_kassier_sets_individual_limit(): void
    {
        $user = $this->user();
        $this->actingAs($this->kassier())->put("/backend/benutzer/{$user->id}/limit", ['overdraft_limit' => '20.00'])
            ->assertSessionHas('status');
        $this->assertSame(2000, $user->fresh()->overdraft_limit);

        $this->put("/backend/benutzer/{$user->id}/limit", ['overdraft_limit' => ''])->assertSessionHas('status');
        $this->assertNull($user->fresh()->overdraft_limit);
    }

    public function test_quantity_is_limited(): void
    {
        $this->setDefaultLimit(100000);
        $this->actingAs($this->user())->post('/konto/bier', $this->tokened('consume', ['quantity' => 11]))->assertSessionHasErrors('quantity');
        $this->post('/konto/bier', $this->tokened('consume', ['quantity' => 0]))->assertSessionHasErrors('quantity');
    }

    public function test_undo_within_ten_minutes_only(): void
    {
        $this->setDefaultLimit(1000);
        $user = $this->user();
        $this->actingAs($user);

        $first = Ledger::consume($user, 1);
        $this->post("/konto/bier/{$first->id}/rueckgaengig")->assertSessionHas('status');
        $this->assertNull(Transaction::query()->find($first->id));

        $second = Ledger::consume($user, 1);
        $this->travel(11)->minutes();
        $this->post("/konto/bier/{$second->id}/rueckgaengig")->assertSessionHas('error');
        $this->assertNotNull(Transaction::query()->find($second->id));
    }

    public function test_user_cannot_undo_others_consumption(): void
    {
        $this->setDefaultLimit(1000);
        $other = $this->user();
        $tx = Ledger::consume($other, 1);

        $this->actingAs($this->user())->post("/konto/bier/{$tx->id}/rueckgaengig")->assertSessionHas('error');
        $this->assertNotNull(Transaction::query()->find($tx->id));
    }

    public function test_deposit_is_pending_until_confirmed(): void
    {
        $user = $this->user();
        $kassier = $this->kassier();

        $this->actingAs($user)->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => '20.50', 'method' => 'twint']))->assertSessionHas('status');
        $tx = Transaction::query()->where('type', 'deposit')->firstOrFail();
        $this->assertSame('pending', $tx->status);
        $this->assertSame(2050, $tx->amount);
        $this->assertSame(0, $user->balance());

        $this->actingAs($kassier)->get('/backend')->assertOk()->assertSee('CHF 20.50');
        $this->post("/backend/einzahlungen/{$tx->id}/bestaetigen")->assertSessionHas('status');

        $tx->refresh();
        $this->assertSame('confirmed', $tx->status);
        $this->assertSame($kassier->id, $tx->confirmed_by);
        $this->assertNotNull($tx->confirmed_at);
        $this->assertSame(2050, $user->balance());

        // A confirmed deposit cannot be confirmed or rejected again.
        $this->post("/backend/einzahlungen/{$tx->id}/ablehnen")->assertSessionHas('error');
    }

    public function test_rejected_deposit_does_not_count(): void
    {
        $user = $this->user();
        $tx = Ledger::reportDeposit($user, 1000, 'cash');

        $this->actingAs($this->kassier())->post("/backend/einzahlungen/{$tx->id}/ablehnen")->assertSessionHas('status');
        $this->assertSame('rejected', $tx->fresh()->status);
        $this->assertSame(0, $user->balance());
    }

    public function test_invalid_deposit_amount(): void
    {
        $this->actingAs($this->user());
        foreach (['0', '-5', 'abc', '1.234'] as $amount) {
            $this->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => $amount, 'method' => 'cash']))->assertSessionHasErrors('amount');
        }
        $this->post('/konto/einzahlung', $this->tokened('deposit', ['amount' => '5', 'method' => 'bitcoin']))->assertSessionHasErrors('method');
    }

    public function test_kassier_books_deposit_and_correction(): void
    {
        $user = $this->user();
        $this->actingAs($this->kassier());

        $this->post("/backend/benutzer/{$user->id}/einzahlung", ['amount' => '50', 'method' => 'cash'])->assertSessionHas('status');
        $this->post("/backend/benutzer/{$user->id}/korrektur", ['amount' => '-2.50', 'note' => ''])->assertSessionHasErrors('note');
        $this->post("/backend/benutzer/{$user->id}/korrektur", ['amount' => '-2.50', 'note' => 'Glas kaputt'])->assertSessionHas('status');

        $this->assertSame(4750, $user->balance());
        $this->assertDatabaseHas('transactions', ['user_id' => $user->id, 'type' => 'correction', 'amount' => -250, 'note' => 'Glas kaputt']);
    }

    public function test_dashboard_shows_twint_box(): void
    {
        Setting::put(AppSettings::TWINT_PHONE, '079 123 45 67');
        Setting::put(AppSettings::TWINT_NAME, 'Kassier Max');

        $this->actingAs($this->user())->get('/konto')->assertOk()
            ->assertSee('079 123 45 67')->assertSee('Kassier Max')->assertSee('data-copy', false);
    }

    public function test_history_is_paginated(): void
    {
        $this->setDefaultLimit(100000);
        $user = $this->user();
        for ($i = 0; $i < 30; $i++) {
            Ledger::consume($user, 1);
        }

        $this->actingAs($user)->get('/konto/verlauf')->assertOk()->assertSee('Seite 1 von 2');
    }
}

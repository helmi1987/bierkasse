<?php

namespace Tests\Feature;

use App\Mail\KassierNotice;
use App\Models\AuditLog;
use App\Models\CashEntry;
use App\Models\GuestSale;
use App\Models\RoundItem;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BeerStock;
use App\Services\CashBook;
use App\Services\Ledger;
use App\Services\Rounds;
use App\Services\Stats;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Runde spendieren": a user pays beers for colleagues (one each) and beers without a name.
 */
class RoundTest extends TestCase
{
    use RefreshDatabase;

    private User $giver;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -3000, 'beers' => 30, 'method' => 'cash', 'note' => 'Kauf']);
        $this->giver = $this->user(['first_name' => 'Gabi', 'last_name' => 'Geber', 'email' => 'gabi@example.test']);
        Ledger::bookDeposit($this->giver, 3000, 'cash', $this->kassier());
    }

    public function test_round_is_not_limited_by_the_stock(): void
    {
        $anna = $this->user(['first_name' => 'Anna']);
        // Stock 30 from setUp; like normal consumption a round may take it below 0.
        Ledger::giveRound($this->giver, [$anna->id], 0);
        $giver2 = $this->user();
        Ledger::bookDeposit($giver2, 10000, 'cash', $this->kassier());
        Ledger::consume($giver2, 10);
        Ledger::consume($giver2, 10);
        Ledger::consume($giver2, 9);
        Ledger::giveRound($giver2, [$anna->id], 5);
        $this->assertSame(-6, BeerStock::current());
    }

    public function test_colleagues_sorted_by_most_recent_beer_then_name(): void
    {
        $zora = $this->user(['first_name' => 'Zora', 'last_name' => 'Zeller']);
        $anna = $this->user(['first_name' => 'Anna', 'last_name' => 'Ammann']);
        $max = $this->user(['first_name' => 'Max', 'last_name' => 'Meier']);
        $paul = $this->user(['first_name' => 'Paul', 'last_name' => 'Pfister']);
        foreach ([$zora, $max] as $u) {
            Ledger::bookDeposit($u, 1500, 'cash', $this->kassier());
        }

        $this->travelTo(now()->subHour());
        Ledger::consume($max, 1);
        $this->travelBack();
        Ledger::consume($zora, 1);

        $names = Rounds::colleagues($this->giver)->map->displayName()->all();
        $this->assertSame(['Zora Zeller', 'Max Meier'], array_slice($names, 0, 2));
        $this->assertLessThan(array_search('Paul Pfister', $names), array_search('Anna Ammann', $names));
        $this->assertNotContains($this->giver->displayName(), $names);

        $html = $this->actingAs($this->giver)->get('/konto')->getContent();
        $this->assertLessThan(strpos($html, 'data-testid="guest-booking"'), strpos($html, 'data-testid="round"'));
    }

    public function test_round_with_colleagues_and_guests_charges_the_giver_and_reduces_the_stock(): void
    {
        [$anna, $beat] = [$this->user(['first_name' => 'Anna', 'last_name' => 'A']), $this->user(['first_name' => 'Beat', 'last_name' => 'B'])];

        $this->actingAs($this->giver)->get('/konto')->assertOk()
            ->assertSeeInOrder(['Runde spendieren', 'Bier für Gast', 'Einzahlung'])
            ->assertSee('Anna A')->assertSee('Beat B')->assertDontSee('name="recipients[]" value="'.$this->giver->id.'"', false)
            ->assertSee('name="recipients[]" value="'.$anna->id.'"', false);

        $this->post('/konto/runde', $this->round(['recipients' => [$anna->id, $beat->id], 'extra' => 2, 'message' => 'Prost zum Geburtstag!']))
            ->assertSessionHas('status', 'Runde spendiert: 4 Biere (CHF 6.00). Prost!');

        $tx = Transaction::query()->where('type', Transaction::TYPE_ROUND)->sole();
        $this->assertSame([$this->giver->id, 4, 150, -600, 'Prost zum Geburtstag!'], [$tx->user_id, $tx->quantity, $tx->unit_price, $tx->amount, $tx->note]);
        $this->assertEqualsCanonicalizing(
            [[$anna->id, 1], [$beat->id, 1], [null, 2]],
            $tx->roundItems->map(fn (RoundItem $i) => [$i->user_id, $i->quantity])->all(),
        );
        $this->assertSame(2400, $this->giver->refresh()->balance());
        $this->assertSame(0, $anna->refresh()->balance());
        $this->assertSame(26, BeerStock::current());
        // The cash book is not affected (like consumption).
        $this->assertSame(0, CashBook::balance()['cash']);

        $this->get('/konto/verlauf')->assertOk()->assertSee('Runde spendiert (4 Biere)')->assertSee('für Anna A, Beat B + 2 Gäste')
            ->assertSee('Prost zum Geburtstag!');
        $this->actingAs($this->kassier())->get('/backend/runden')->assertOk()->assertSee('Gabi Geber')->assertSee('für Anna A, Beat B + 2 Gäste');
        $this->get('/backend/benutzer/'.$this->giver->id)->assertOk()->assertSee('Spendiert: 4 Biere');
    }

    public function test_overdraft_limit_is_respected_like_consume(): void
    {
        $poor = $this->user();
        $colleague = $this->user();
        $this->actingAs($poor)->post('/konto/runde', $this->round(['recipients' => [$colleague->id]]))
            ->assertSessionHas('error', 'Bitte zuerst einzahlen');
        $this->assertSame(0, Transaction::query()->where('type', Transaction::TYPE_ROUND)->count());

        $poor->forceFill(['overdraft_limit' => 300])->save();
        $this->post('/konto/runde', $this->round(['extra' => 3]))->assertSessionHas('error', 'Bitte zuerst einzahlen');
        $this->post('/konto/runde', $this->round(['extra' => 2]))->assertSessionHas('status');
        $this->assertSame(-300, $poor->refresh()->balance());
    }

    public function test_not_allowed_while_a_payout_is_pending(): void
    {
        $colleague = $this->user();
        Ledger::requestPayout($this->giver);

        $this->actingAs($this->giver)->get('/konto')->assertOk()->assertDontSee('data-testid="round"', false);
        $this->post('/konto/runde', $this->round(['recipients' => [$colleague->id]]))
            ->assertSessionHas('error', 'Eine Auszahlung ist beantragt – keine weiteren Buchungen möglich.');
        $this->assertSame(0, RoundItem::query()->count());
    }

    public function test_invalid_rounds_are_refused(): void
    {
        $left = $this->user(['status' => User::STATUS_LEFT]);
        $this->actingAs($this->giver);

        $this->post('/konto/runde', $this->round())->assertSessionHas('error', 'Bitte mindestens ein Bier wählen.');
        $this->post('/konto/runde', $this->round(['recipients' => [$this->giver->id]]))->assertSessionHas('error');
        $this->post('/konto/runde', $this->round(['recipients' => [$left->id]]))->assertSessionHas('error');
        $this->post('/konto/runde', $this->round(['extra' => 21]))->assertSessionHasErrors('extra');
        $this->post('/konto/runde', $this->round(['extra' => 1, 'message' => str_repeat('x', 141)]))->assertSessionHasErrors('message');

        $many = collect(range(1, 11))->map(fn () => $this->user()->id)->all();
        $this->post('/konto/runde', $this->round(['recipients' => $many, 'extra' => 20]))->assertSessionHas('error', 'Höchstens 30 Biere pro Runde.');

        $this->assertSame(0, Transaction::query()->where('type', Transaction::TYPE_ROUND)->count());
    }

    public function test_setting_off_hides_the_card_and_returns_404(): void
    {
        $this->assertTrue(AppSettings::roundsAllowed());
        $colleague = $this->user();

        // Kassiers switch it (like the guest switches); normal users cannot.
        $this->actingAs($this->user())->get('/backend/runden')->assertForbidden();
        $this->actingAs($this->kassier())->put('/backend/runden', [])->assertSessionHas('status');
        $this->assertFalse(AppSettings::roundsAllowed());

        $this->actingAs($this->giver)->get('/konto')->assertOk()->assertDontSee('Runde spendieren');
        $this->post('/konto/runde', $this->round(['recipients' => [$colleague->id]]))->assertNotFound();
        $this->get('/konto/einstellungen')->assertOk()->assertDontSee('Mail bei spendiertem Bier');

        $this->actingAs($this->admin())->put('/backend/runden', ['rounds_enabled' => '1']);
        $this->assertTrue(AppSettings::roundsAllowed());
    }

    public function test_undo_by_the_giver_within_the_window_only(): void
    {
        $colleague = $this->user();
        $this->actingAs($this->giver)->post('/konto/runde', $this->round(['recipients' => [$colleague->id], 'extra' => 1]));
        $tx = Transaction::query()->where('type', Transaction::TYPE_ROUND)->sole();
        $this->assertSame(28, BeerStock::current());

        // Not by somebody else.
        $this->actingAs($colleague)->post('/konto/bier/'.$tx->id.'/rueckgaengig')->assertSessionHas('error');
        $this->assertModelExists($tx);

        $this->actingAs($this->giver)->get('/konto')->assertSee('/konto/bier/'.$tx->id.'/rueckgaengig', false);
        $this->post('/konto/bier/'.$tx->id.'/rueckgaengig')->assertSessionHas('status', 'Runde zurückgenommen.');
        $this->assertModelMissing($tx);
        $this->assertSame(0, RoundItem::query()->count());
        $this->assertSame(3000, $this->giver->refresh()->balance());
        $this->assertSame(30, BeerStock::current());

        $this->post('/konto/runde', $this->round(['extra' => 1]));
        $late = Transaction::query()->where('type', Transaction::TYPE_ROUND)->sole();
        $this->travel(AppSettings::undoMinutes('round') + 1)->minutes();
        $this->post('/konto/bier/'.$late->id.'/rueckgaengig')->assertSessionHas('error', 'Das kann nicht mehr zurückgenommen werden.');
        $this->assertModelExists($late);
    }

    public function test_recipients_see_a_notice_but_get_no_mail(): void
    {
        $anna = $this->user(['first_name' => 'Anna', 'last_name' => 'A', 'email' => 'anna@example.test']);
        $withMail = $this->user(['email' => 'withmail@example.test']);
        $noAddress = $this->user(['email' => null]);
        $other = $this->user(['email' => 'other@example.test']);

        $this->actingAs($this->giver)->post('/konto/runde', $this->round(['recipients' => [$anna->id, $withMail->id, $noAddress->id], 'message' => 'Prost zum Geburtstag!']))
            ->assertSessionHas('status');

        Mail::assertNothingSent();
        Mail::assertNotSent(KassierNotice::class, fn (KassierNotice $m) => $m->hasTo('anna@example.test'));

        $this->actingAs($anna)->get('/konto')->assertOk()
            ->assertSee('🍺 Gabi Geber hat dir ein Bier spendiert – Prost zum Geburtstag!');
        $this->actingAs($other)->get('/konto')->assertOk()->assertDontSee('hat dir ein Bier spendiert');

        // Only the last day.
        $this->travel(2)->days();
        $this->actingAs($anna)->get('/konto')->assertOk()->assertDontSee('hat dir ein Bier spendiert');

    }

    public function test_statistics_count_the_beers_at_the_drinker(): void
    {
        $anna = $this->user(['first_name' => 'Anna', 'last_name' => 'A']);
        $hidden = $this->user(['first_name' => 'Hans', 'last_name' => 'Hidden', 'stats_opt_out' => true]);
        Ledger::consume($this->giver, 2);
        GuestSale::query()->create(['beers' => 1, 'unit_price' => 150, 'amount' => 150, 'method' => 'cash']);
        $this->actingAs($this->giver)->post('/konto/runde', $this->round(['recipients' => [$anna->id, $hidden->id], 'extra' => 3]));

        $global = Stats::global();
        // 2 consumed + 1 guest sale + 5 round beers, nothing counted twice.
        $this->assertSame(8, $global['total_beers']);
        $this->assertSame(8, array_sum(array_column($global['months'], 'beers')));
        $this->assertSame(8, array_sum(array_column($global['weekdays'], 'beers')));
        $ranking = collect($global['ranking'])->keyBy('user_id');
        $this->assertSame(['Gäste (eingeladen)', 3], [$ranking[-1]['name'], $ranking[-1]['beers']]);
        $this->assertSame(2, $ranking[$this->giver->id]['beers']);
        $this->assertSame(1, $ranking[$anna->id]['beers']);
        $this->assertSame(1, $ranking[0]['beers']);
        // Opt-out: not named, but in the totals.
        $this->assertFalse($ranking->has($hidden->id));

        $giverStats = Stats::forUser($this->giver);
        $this->assertSame([2, 5, 300 + 750], [$giverStats['total_beers'], $giverStats['given'], $giverStats['total_amount']]);
        $annaStats = Stats::forUser($anna);
        $this->assertSame([1, 0, 0], [$annaStats['total_beers'], $annaStats['given'], $annaStats['total_amount']]);
        $this->assertSame(1, Stats::forUser($hidden)['total_beers']);

        $this->get('/konto')->assertOk()->assertSee('data-testid="round-given"', false);
        $this->get('/statistik')->assertOk()->assertSee('Gäste (eingeladen)')->assertSee('Anna A')->assertDontSee('Hans Hidden');
    }

    public function test_open_items_keep_the_price_of_the_round(): void
    {
        $user = $this->user(['overdraft_limit' => 10000]);
        $this->actingAs($user)->post('/konto/runde', $this->round(['extra' => 3]));
        $this->setPrice(200);
        Ledger::consume($user, 1);

        $open = Ledger::openItems($user);
        $this->assertSame(650, $open['total']);
        $this->assertSame([
            ['unit_price' => 150, 'quantity' => 3, 'amount' => 450],
            ['unit_price' => 200, 'quantity' => 1, 'amount' => 200],
        ], $open['beers']);
        $this->assertSame(0, $open['other']);
    }

    public function test_double_submit_books_once(): void
    {
        $colleague = $this->user();
        $data = $this->actingAs($this->giver)->round(['recipients' => [$colleague->id]]);
        $this->post('/konto/runde', $data)->assertSessionHas('status');
        $this->post('/konto/runde', $data)->assertSessionHas('warning');
        $this->post('/konto/runde', ['recipients' => [$colleague->id]])->assertSessionHas('warning');

        $this->assertSame(1, Transaction::query()->where('type', Transaction::TYPE_ROUND)->count());
        $this->assertSame(29, BeerStock::current());
    }

    public function test_low_stock_mail_counts_rounds(): void
    {
        Setting::put(AppSettings::STOCK_MINIMUM, 28);
        $kassier = $this->kassier(['email' => 'kassier@example.test']);
        Ledger::bookDeposit($this->giver, 1, 'cash', $kassier);

        $this->actingAs($this->giver)->post('/konto/runde', $this->round(['extra' => 3]));
        $this->assertSame(27, BeerStock::current());
        Mail::assertSent(KassierNotice::class, fn (KassierNotice $m) => $m->hasTo('kassier@example.test') && str_contains($m->noticeSubject, '27'));
    }

    public function test_round_including_self_books_own_beer(): void
    {
        $this->setPrice(150);
        $anna = $this->user(['first_name' => 'Anna']);
        $beat = $this->user(['first_name' => 'Beat']);
        $this->actingAs($this->giver);

        $this->get('/konto')->assertOk()
            ->assertSee('Runde spendieren (0)')->assertSee('Runde inkl. mir (1)')
            ->assertSee('name="include_self" value="1"', false);

        $this->post('/konto/runde', $this->round(['recipients' => [$anna->id, $beat->id], 'extra' => 1, 'include_self' => '1']))
            ->assertSessionHas('status');

        $tx = Transaction::query()->where('type', Transaction::TYPE_ROUND)->sole();
        $this->assertSame(4, $tx->quantity);
        $this->assertSame(-600, $tx->amount);
        $this->assertSame(3000 - 600, $this->giver->refresh()->balance());
        $this->assertSame(1, RoundItem::query()->where('transaction_id', $tx->id)->where('user_id', $this->giver->id)->sum('quantity'));

        // Statistics: the own beer counts at the giver (drinker), not as "spendiert".
        $stats = Stats::forUser($this->giver);
        $this->assertSame(1, $stats['total_beers']);
        $this->assertSame(3, $stats['given']);
        $this->assertSame(3, Rounds::beersGiven($this->giver));
        $this->assertSame(1, Stats::forUser($anna)['total_beers']);
        $ranking = collect(Stats::global()['ranking'])->keyBy('user_id');
        $this->assertSame(1, $ranking[$this->giver->id]['beers']);

        // No notice or mail to oneself.
        $this->assertTrue(Rounds::receivedRecently($this->giver)->isEmpty());
        Mail::assertNotSent(KassierNotice::class, fn ($m) => $m->hasTo('gabi@example.test'));

        $audit = AuditLog::query()->where('action', 'round.create')->sole();
        $this->assertTrue($audit->newValues()['include_self']);
    }

    public function test_round_including_self_needs_another_beer_and_undo(): void
    {
        $this->setPrice(150);
        $anna = $this->user(['first_name' => 'Anna']);
        $this->actingAs($this->giver);
        $this->get('/konto')->assertSee('data-round-submit-self data-label="'.e(__('round.submit_self')).'" disabled', false);

        $this->post('/konto/runde', $this->round(['include_self' => '1']))->assertSessionHas('error', __('round.none_selected'));
        $this->assertSame(0, Transaction::query()->where('type', Transaction::TYPE_ROUND)->count());

        $this->post('/konto/runde', $this->round(['recipients' => [$anna->id], 'include_self' => '1']))->assertSessionHas('status');
        $tx = Transaction::query()->where('type', Transaction::TYPE_ROUND)->sole();
        $this->assertSame(2, $tx->quantity);
        $this->assertSame(1, Stats::forUser($this->giver)['total_beers']);
        $this->assertSame(1, Stats::forUser($this->giver)['given']);

        $this->post("/konto/bier/{$tx->id}/rueckgaengig")->assertSessionHas('status', __('round.undone'));
        $this->assertSame(0, Transaction::query()->where('type', Transaction::TYPE_ROUND)->count());
        $this->assertSame(0, RoundItem::query()->count());
        $this->assertSame(3000, $this->giver->refresh()->balance());
    }

    public function test_round_including_self_counts_for_limit_and_maximum(): void
    {
        $this->setPrice(150);
        $poor = $this->user(['overdraft_limit' => 300]);
        $colleague = $this->user();
        $this->actingAs($poor);

        $this->post('/konto/runde', $this->round(['recipients' => [$colleague->id], 'extra' => 1, 'include_self' => '1']))
            ->assertSessionHas('error', 'Bitte zuerst einzahlen');
        $this->post('/konto/runde', $this->round(['recipients' => [$colleague->id], 'include_self' => '1']))->assertSessionHas('status');
        $this->assertSame(-300, $poor->refresh()->balance());

        $this->actingAs($this->giver);
        $this->post('/konto/runde', $this->round(['extra' => Rounds::MAX_EXTRA, 'recipients' => array_map(fn () => $this->user()->id, range(1, Rounds::MAX_BEERS - Rounds::MAX_EXTRA)), 'include_self' => '1']))
            ->assertSessionHas('error', __('round.too_many', ['max' => Rounds::MAX_BEERS]));
    }

    private function round(array $overrides = []): array
    {
        return $this->tokened('round', array_merge(['recipients' => [], 'extra' => 0], $overrides));
    }
}

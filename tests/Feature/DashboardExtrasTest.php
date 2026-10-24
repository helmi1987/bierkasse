<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Favorites;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Dashboard: dismissable "erhalten" notices (1 day), merged cards, pinned colleagues.
 */
class DashboardExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $giver;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        CashEntry::query()->create(['type' => 'expense_beer', 'date' => '2026-10-01', 'amount' => -3000, 'beers' => 30, 'method' => 'cash', 'note' => 'Kauf']);
        $this->giver = $this->user(['first_name' => 'Gabi', 'last_name' => 'Geber']);
        Ledger::bookDeposit($this->giver, 5000, 'cash', $this->kassier());
    }

    /** @return array{0: Transaction, 1: Transaction} round and gift recipient row for $anna (and $beat) */
    private function giveBoth(User $anna, User $beat): array
    {
        $round = Ledger::giveRound($this->giver, [$anna->id, $beat->id], 0, 'Prost');
        $gift = Ledger::giveBeers($this->giver, [$anna->id, $beat->id], 2, 'Danke');

        return [$round, $gift->giftParts()->where('user_id', $anna->id)->firstOrFail()];
    }

    public function test_notices_show_for_one_day_only(): void
    {
        $anna = $this->user(['first_name' => 'Anna']);
        $beat = $this->user(['first_name' => 'Beat']);
        $this->giveBoth($anna, $beat);

        $this->actingAs($anna)->get('/konto')->assertOk()->assertSee('hat dir ein Bier spendiert')->assertSee('2 Biere geschenkt');
        $this->travel(25)->hours();
        $this->actingAs($anna)->get('/konto')->assertOk()->assertDontSee('hat dir ein Bier spendiert')->assertDontSee('2 Biere geschenkt');
    }

    public function test_dismissed_notice_never_shows_again_and_only_for_that_user(): void
    {
        $anna = $this->user(['first_name' => 'Anna']);
        $beat = $this->user(['first_name' => 'Beat']);
        [$round, $gift] = $this->giveBoth($anna, $beat);

        $this->actingAs($anna)->get('/konto')->assertSee('data-testid="round-notice"', false)->assertSee('notice-close', false);
        $this->actingAs($anna)->post('/konto/hinweis/'.$round->id)->assertRedirect();
        $this->actingAs($anna)->get('/konto')->assertDontSee('data-testid="round-notice"', false)->assertSee('data-testid="gift-notice"', false);
        $this->actingAs($anna)->post('/konto/hinweis/'.$gift->id)->assertRedirect();
        $this->actingAs($anna)->post('/konto/hinweis/'.$gift->id)->assertRedirect(); // idempotent
        $this->actingAs($anna)->get('/konto')->assertDontSee('data-testid="gift-notice"', false);
        $this->assertSame(2, DB::table('notice_dismissals')->where('user_id', $anna->id)->count());

        // Beat still sees both; Anna dismissing does not touch him.
        $this->actingAs($beat)->get('/konto')->assertSee('data-testid="round-notice"', false)->assertSee('data-testid="gift-notice"', false);
    }

    public function test_foreign_or_unrelated_transactions_cannot_be_dismissed(): void
    {
        $anna = $this->user();
        $beat = $this->user();
        $other = $this->user();
        [$round, $gift] = $this->giveBoth($anna, $beat);

        $this->actingAs($other)->post('/konto/hinweis/'.$round->id)->assertNotFound();
        $this->actingAs($other)->post('/konto/hinweis/'.$gift->id)->assertNotFound();
        $this->actingAs($this->giver)->post('/konto/hinweis/'.$round->id)->assertNotFound();
        $this->assertSame(0, DB::table('notice_dismissals')->count());
    }

    public function test_deposit_and_twint_cards_are_merged(): void
    {
        $this->actingAs($this->user())->get('/konto')->assertOk()
            ->assertSee('<h2>Einzahlen</h2>', false)
            ->assertDontSee('<h2>Einzahlung melden</h2>', false)
            ->assertDontSee('<h2>TWINT</h2>', false);
    }

    public function test_statistics_and_bookings_are_one_card(): void
    {
        $this->actingAs($this->giver)->get('/konto')->assertOk()
            ->assertSee('<h2>Meine Buchungen &amp; Statistik</h2>', false)
            ->assertDontSee('<h2>Meine Statistik</h2>', false)
            ->assertDontSee('<h2>Letzte Buchungen</h2>', false);
    }

    public function test_pin_and_unpin_colleagues_per_user_and_order(): void
    {
        $anna = $this->user(['first_name' => 'Anna', 'last_name' => 'Ammann']);
        $beat = $this->user(['first_name' => 'Beat', 'last_name' => 'Bucher']);
        $cara = $this->user(['first_name' => 'Cara', 'last_name' => 'Cotti']);
        $other = $this->user(['first_name' => 'Olga', 'last_name' => 'Oertli']);

        $order = fn (User $viewer) => $this->actingAs($viewer)->get('/konto')->assertOk();
        $order($this->giver)->assertSeeInOrder(['Anna Ammann', 'Beat Bucher', 'Cara Cotti']);

        $this->actingAs($this->giver)->postJson('/konto/favorit/round/'.$cara->id)->assertOk()->assertJson(['pinned' => true]);
        $this->actingAs($this->giver)->post('/konto/favorit/round/'.$beat->id)->assertRedirect();
        $this->assertEqualsCanonicalizing([$cara->id, $beat->id], Favorites::ids($this->giver, 'round'));
        $this->assertSame([], Favorites::ids($this->giver, 'gift'));

        // Pinned first (in the list order) in the round list only; the gift list keeps the plain order.
        $html = $order($this->giver)->getContent();
        $this->assertSame(1, preg_match_all('/Beat Bucher.*?Cara Cotti.*?Anna Ammann/s', $html), 'only the round list starts with the favourites');
        $this->assertSame(1, preg_match_all('/Anna Ammann.*?Beat Bucher.*?Cara Cotti/s', $html), 'the gift list keeps the plain order');
        $this->assertStringContainsString('aria-pressed="true"', $html);

        // Other users have their own (empty) favourites.
        $this->assertSame([], Favorites::ids($other, 'round'));
        $order($other)->assertSeeInOrder(['Anna Ammann', 'Beat Bucher', 'Cara Cotti']);

        // Unpin.
        $this->actingAs($this->giver)->postJson('/konto/favorit/round/'.$cara->id)->assertOk()->assertJson(['pinned' => false]);
        $this->assertSame([$beat->id], Favorites::ids($this->giver, 'round'));
    }

    public function test_favourites_are_separate_per_list(): void
    {
        $anna = $this->user(['first_name' => 'Anna', 'last_name' => 'Ammann']);
        $beat = $this->user(['first_name' => 'Beat', 'last_name' => 'Bucher']);

        $this->actingAs($this->giver)->postJson('/konto/favorit/gift/'.$beat->id)->assertOk()->assertJson(['pinned' => true]);
        $this->assertSame([$beat->id], Favorites::ids($this->giver, 'gift'));
        $this->assertSame([], Favorites::ids($this->giver, 'round'));

        // Same colleague can be pinned independently in the round list, and unpinning one list leaves the other.
        $this->actingAs($this->giver)->postJson('/konto/favorit/round/'.$beat->id)->assertJson(['pinned' => true]);
        $this->actingAs($this->giver)->postJson('/konto/favorit/round/'.$anna->id)->assertJson(['pinned' => true]);
        $this->actingAs($this->giver)->postJson('/konto/favorit/gift/'.$beat->id)->assertJson(['pinned' => false]);
        $this->assertSame([], Favorites::ids($this->giver, 'gift'));
        $this->assertEqualsCanonicalizing([$beat->id, $anna->id], Favorites::ids($this->giver, 'round'));

        // Order per list: Beat pinned in gift only -> gift list "Beat, Anna", round list keeps "Anna, Beat".
        $this->actingAs($this->giver)->postJson('/konto/favorit/round/'.$beat->id)->assertJson(['pinned' => false]);
        $this->actingAs($this->giver)->postJson('/konto/favorit/round/'.$anna->id)->assertJson(['pinned' => false]);
        $this->actingAs($this->giver)->postJson('/konto/favorit/gift/'.$beat->id)->assertJson(['pinned' => true]);
        $html = $this->actingAs($this->giver)->get('/konto')->assertOk()->getContent();
        $gift = substr($html, strpos($html, 'data-list="gift"'));
        $round = substr($html, strpos($html, 'data-list="round"'), strpos($html, 'data-list="gift"') - strpos($html, 'data-list="round"'));
        $this->assertLessThan(strpos($round, 'Beat Bucher'), strpos($round, 'Anna Ammann'));
        $this->assertLessThan(strpos($gift, 'Anna Ammann'), strpos($gift, 'Beat Bucher'));
    }

    public function test_pin_with_an_invalid_list_is_not_found(): void
    {
        $anna = $this->user(['first_name' => 'Anna']);

        $this->actingAs($this->giver)->postJson('/konto/favorit/beer/'.$anna->id)->assertNotFound();
        $this->actingAs($this->giver)->postJson('/konto/favorit/'.$anna->id)->assertNotFound();
        $this->assertSame(0, DB::table('user_favorites')->count());
    }

    public function test_migration_copies_existing_favourites_into_both_lists(): void
    {
        $anna = $this->user(['first_name' => 'Anna']);
        $migration = require database_path('migrations/2026_10_24_000200_add_list_to_user_favorites.php');

        // Back to the 1.14 shape (shared favourites), then migrate with an existing row.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('user_favorites', 'list'));
        DB::table('user_favorites')->insert(['user_id' => $this->giver->id, 'favorite_user_id' => $anna->id, 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();
        $this->assertSame([$anna->id], Favorites::ids($this->giver, 'round'));
        $this->assertSame([$anna->id], Favorites::ids($this->giver, 'gift'));
        $this->assertSame(2, DB::table('user_favorites')->count());

        // Down merges both lists back into one row per colleague.
        DB::table('user_favorites')->where(['list' => 'round'])->delete();
        $migration->down();
        $this->assertSame(1, DB::table('user_favorites')->count());
        $migration->up();
        $this->assertSame(2, DB::table('user_favorites')->count());
    }

    public function test_pinning_ignores_inactive_or_unknown_users_and_oneself(): void
    {
        $gone = $this->user(['status' => User::STATUS_LEFT]);
        $anna = $this->user(['first_name' => 'Anna']);

        $this->actingAs($this->giver)->postJson('/konto/favorit/round/'.$gone->id)->assertNotFound();
        $this->actingAs($this->giver)->postJson('/konto/favorit/gift/'.$this->giver->id)->assertNotFound();
        $this->actingAs($this->giver)->postJson('/konto/favorit/round/999999')->assertNotFound();

        // A favourite who became inactive later disappears from the list without an error; unpinning still works.
        $this->actingAs($this->giver)->postJson('/konto/favorit/round/'.$anna->id)->assertJson(['pinned' => true]);
        $anna->forceFill(['status' => User::STATUS_LEFT])->save();
        $this->actingAs($this->giver)->get('/konto')->assertOk()->assertDontSee('Anna');
        $this->actingAs($this->giver)->postJson('/konto/favorit/round/'.$anna->id)->assertJson(['pinned' => false]);
        $this->assertSame([], Favorites::ids($this->giver, 'round'));
    }
}

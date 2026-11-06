<?php

namespace Tests\Feature;

use App\Services\Ledger;
use App\Services\Stats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_opt_out_user_is_not_named_but_counted_in_total(): void
    {
        $this->setDefaultLimit(100000);
        $visible = $this->user(['first_name' => 'Vreni', 'last_name' => 'Sichtbar']);
        $hidden = $this->user(['first_name' => 'Heiri', 'last_name' => 'Versteckt', 'stats_opt_out' => true]);
        Ledger::consume($visible, 2);
        Ledger::consume($hidden, 5);

        $stats = Stats::global();
        $this->assertSame(7, $stats['total_beers']);
        $this->assertSame(['Vreni Sichtbar'], array_column($stats['ranking'], 'name'));
        $this->assertSame(7, array_sum(array_column($stats['months'], 'beers')));
        $this->assertSame(7, array_sum(array_column($stats['weekdays'], 'beers')));

        $this->actingAs($visible)->get('/statistik')->assertOk()
            ->assertSee('Vreni Sichtbar')->assertDontSee('Versteckt')->assertSee('<svg class="chart"', false);
        // The former kassier statistics tab redirects to the statistics page (1.20.0).
        $this->actingAs($this->kassier())->get('/admin/statistik')->assertRedirect(route('stats'));
        $this->get('/statistik')->assertOk()->assertDontSee('Versteckt');
    }

    public function test_user_toggles_stats_visibility(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/konto/statistik', [])->assertSessionHas('status');
        $this->assertTrue($user->fresh()->stats_opt_out);

        $this->post('/konto/statistik', ['show' => '1']);
        $this->assertFalse($user->fresh()->stats_opt_out);
    }

    public function test_own_statistics(): void
    {
        $this->setPrice(150);
        $this->setDefaultLimit(100000);
        $user = $this->user();
        Ledger::consume($user, 3);

        $stats = Stats::forUser($user);
        $this->assertSame(3, $stats['total_beers']);
        $this->assertSame(450, $stats['total_amount']);
        $this->assertSame(3, end($stats['months'])['beers']);

        $this->actingAs($user)->get('/konto')->assertOk()->assertSee('CHF 4.50');
    }

    public function test_ranking_uses_competition_ranking_on_ties(): void
    {
        $this->setDefaultLimit(100000);
        $a = $this->user(['first_name' => 'Anna', 'last_name' => 'A']);
        $b = $this->user(['first_name' => 'Beat', 'last_name' => 'B']);
        $c = $this->user(['first_name' => 'Cla', 'last_name' => 'C']);
        $d = $this->user(['first_name' => 'Dora', 'last_name' => 'D']);
        Ledger::consume($a, 5);
        Ledger::consume($b, 5);
        Ledger::consume($c, 3);
        Ledger::consume($d, 1);

        $ranking = Stats::global()['ranking'];
        $this->assertSame([1, 1, 3, 4], array_column($ranking, 'rank'));
        $this->assertSame([5, 5, 3, 1], array_column($ranking, 'beers'));

        // The top-N limit keeps everyone tied with place N.
        $this->assertSame([1, 1], array_column(Stats::global(1)['ranking'], 'rank'));
        $this->assertSame([1, 1, 3], array_column(Stats::global(3)['ranking'], 'rank'));

        $this->actingAs($a)->get('/statistik')->assertOk()
            ->assertSeeInOrder(['<span class="rank">1</span>', '<span class="rank">1</span>', '<span class="rank">3</span>', '<span class="rank">4</span>'], false)
            ->assertSee('class="rank-1', false)->assertDontSee('class="rank-2', false)->assertSee('class="rank-3', false);
    }
}

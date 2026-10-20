<?php

namespace Tests\Unit;

use App\Support\DepositSuggestions;
use PHPUnit\Framework\TestCase;

class DepositSuggestionsTest extends TestCase
{
    public function test_automatic_amounts_per_price(): void
    {
        // Smallest multiple of the price divisible by CHF 10, then 2x, 3x, 5x.
        $this->assertSame([3000, 6000, 9000, 15000], DepositSuggestions::defaults(150));
        $this->assertSame([3000, 6000, 9000, 15000], DepositSuggestions::defaults(120));
        $this->assertSame([7000, 14000, 21000, 35000], DepositSuggestions::defaults(175));
        $this->assertSame([1000, 2000, 3000, 5000], DepositSuggestions::defaults(200));
        $this->assertSame([], DepositSuggestions::defaults(0));
    }

    public function test_automatic_suggestions_in_whole_beers(): void
    {
        $this->assertSame(
            [[20, 3000], [40, 6000], [60, 9000], [100, 15000]],
            array_map(fn ($s) => [$s['beers'], $s['amount']], DepositSuggestions::for(500, 150)),
        );
        $this->assertSame(0, DepositSuggestions::defaultIndex(DepositSuggestions::for(0, 150)));
    }

    public function test_minus_is_added_to_the_amount(): void
    {
        $first = DepositSuggestions::for(-450, 150)[0];

        $this->assertSame(['beers' => 20, 'amount' => 3450, 'debt' => 450], $first);
    }

    public function test_configured_amounts_are_rounded_up_after_price_change(): void
    {
        // Configured at CHF 1.50 (30/60), price now CHF 1.75.
        $this->assertSame([3150, 6125], DepositSuggestions::amounts(175, [3000, 6000]));
        $this->assertSame(
            [[18, 3150], [35, 6125]],
            array_map(fn ($s) => [$s['beers'], $s['amount']], DepositSuggestions::for(0, 175, [3000, 6000])),
        );
        $this->assertSame([], DepositSuggestions::for(0, 0, [3000]));
    }

    public function test_nearest_valid_amounts(): void
    {
        $this->assertSame([2400, 2550], DepositSuggestions::nearest(2500, 150));
        $this->assertSame([150], DepositSuggestions::nearest(100, 150));
    }
}

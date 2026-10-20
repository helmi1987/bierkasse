<?php

namespace Tests\Unit;

use App\Support\DepositSuggestions;
use PHPUnit\Framework\TestCase;

class DepositSuggestionsTest extends TestCase
{
    public function test_whole_francs_and_whole_beers_at_default_price(): void
    {
        $this->assertSame(
            [[8, 1200], [14, 2100], [20, 3000], [34, 5100]],
            array_map(fn ($s) => [$s['beers'], $s['amount']], DepositSuggestions::for(500, 150)),
        );
    }

    public function test_minus_is_covered_first(): void
    {
        $first = DepositSuggestions::for(-450, 150)[0];

        $this->assertSame(450, $first['debt']);
        $this->assertSame(450 + $first['beers'] * 150, $first['amount']);
        $this->assertSame(0, $first['amount'] % 100);
    }

    public function test_falls_back_to_plain_beer_multiples(): void
    {
        foreach (DepositSuggestions::for(-420, 150) as $s) {
            $this->assertSame(420 + $s['beers'] * 150, $s['amount']);
        }
        $this->assertSame([], DepositSuggestions::for(0, 0));
    }
}

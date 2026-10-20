<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_format(): void
    {
        $this->assertSame('CHF 1.50', Money::format(150));
        $this->assertSame('CHF 0.05', Money::format(5));
        $this->assertSame('CHF -12.30', Money::format(-1230));
        $this->assertSame('CHF 0.00', Money::format(0));
    }

    public function test_parse(): void
    {
        $this->assertSame(150, Money::parse('1.50'));
        $this->assertSame(150, Money::parse('1,5'));
        $this->assertSame(2000, Money::parse('20'));
        $this->assertSame(-250, Money::parse('-2.50'));
        $this->assertSame(100000, Money::parse("1'000"));
        $this->assertNull(Money::parse('abc'));
        $this->assertNull(Money::parse('1.234'));
        $this->assertNull(Money::parse(''));
    }
}

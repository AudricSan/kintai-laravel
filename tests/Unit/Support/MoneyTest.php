<?php

namespace Tests\Unit\Support;

use App\Models\Store;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_formats_euro_with_comma_decimals_and_suffix(): void
    {
        $this->assertSame('8.400,50 €', Money::format(8400.5, 'EUR'));
    }

    public function test_formats_yen_without_decimals_in_both_styles(): void
    {
        $this->assertSame('8,400円', Money::format(8400, 'JPY', 'kanji'));
        $this->assertSame('8,400¥', Money::format(8400, 'JPY', 'international'));
        $this->assertSame('8,400円', Money::format(8400, 'jpy', 'unknown'));
    }

    public function test_other_currencies(): void
    {
        $this->assertSame('$1,234.50', Money::format(1234.5, 'USD'));
        $this->assertSame('CHF 1\'234.50', Money::format(1234.5, 'CHF'));
        $this->assertSame('12.00 ABC', Money::format(12, 'a<b>c'));
    }

    public function test_for_store_uses_its_currency_and_style(): void
    {
        $store = (new Store)->forceFill(['currency' => 'JPY', 'currency_symbol_style' => 'international']);

        $this->assertSame('1,000¥', Money::forStore(1000, $store));
    }
}

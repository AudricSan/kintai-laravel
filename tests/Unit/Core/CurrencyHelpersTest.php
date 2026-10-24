<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * format_currency()/currency_symbol() : le yen peut s'afficher en kanji (円, historique)
 * ou en symbole international (¥) selon le paramètre store currency_symbol_style.
 */
final class CurrencyHelpersTest extends TestCase
{
    public function testCurrencySymbolDefaultsToKanjiForJpy(): void
    {
        $this->assertSame('円', currency_symbol('JPY'));
        $this->assertSame('円', currency_symbol('jpy', 'kanji'));
    }

    public function testCurrencySymbolUsesInternationalStyleWhenRequested(): void
    {
        $this->assertSame('¥', currency_symbol('JPY', 'international'));
    }

    public function testCurrencySymbolIgnoresStyleForNonJpyCurrencies(): void
    {
        $this->assertSame('€', currency_symbol('EUR', 'international'));
        $this->assertSame('$', currency_symbol('USD', 'international'));
    }

    public function testFormatCurrencyAppliesJpySymbolStyle(): void
    {
        $this->assertSame('1,000円', format_currency(1000, 'JPY'));
        $this->assertSame('1,000¥', format_currency(1000, 'JPY', 'international'));
    }

    public function testFormatCurrencyKeepsEurUnaffectedByStyle(): void
    {
        $this->assertSame('1.000,50 €', format_currency(1000.5, 'EUR', 'international'));
    }

    public function testStoreCurrencyStyleDefaultsToKanjiWhenUnset(): void
    {
        $this->assertSame('kanji', store_currency_style(null));
        $this->assertSame('kanji', store_currency_style([]));
        $this->assertSame('kanji', store_currency_style(['currency_symbol_style' => 'unknown']));
    }

    public function testStoreCurrencyStyleReadsInternationalFromStoreArray(): void
    {
        $this->assertSame('international', store_currency_style(['currency_symbol_style' => 'international']));
    }
}

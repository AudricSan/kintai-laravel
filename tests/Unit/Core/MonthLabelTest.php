<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * Noms de mois (audit n°8) : ils ne venaient que du bundle salary-report, et DateTime::format('F Y') les donnait
 * toujours en anglais. Les clés January…December et month_year appartiennent désormais au Core, dans les trois langues.
 */
final class MonthLabelTest extends TestCase
{
    public function testEveryMonthIsTranslatedByTheCoreInEveryLanguage(): void
    {
        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October',
            'November', 'December'];
        foreach (['fr', 'en', 'ja'] as $locale) {
            $lang = json_decode((string) file_get_contents(dirname(__DIR__, 3) . "/lang/{$locale}.json"), true);
            foreach ([...$months, 'month_year', 'date_long'] as $key) {
                $this->assertNotEmpty($lang[$key] ?? null, "{$locale} : {$key}");
            }
        }
    }

    public function testInvalidDatesAreReturnedAsIs(): void
    {
        $this->assertSame('pas-une-date', month_label('pas-une-date'));
    }

    public function testTheLabelCarriesTheYear(): void
    {
        $this->assertStringContainsString('2026', month_label('2026-10'));
        $this->assertStringContainsString('2026', month_label('2026-10-15'));
    }

    public function testALongDateCarriesDayAndYear(): void
    {
        $label = date_label('2026-10-15');
        $this->assertStringContainsString('15', $label);
        $this->assertStringContainsString('2026', $label);
        $this->assertSame('pas-une-date', date_label('pas-une-date'));
    }
}

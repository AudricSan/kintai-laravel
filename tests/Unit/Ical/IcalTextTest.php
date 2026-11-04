<?php

namespace Tests\Unit\Ical;

use App\Ical\IcalText;
use PHPUnit\Framework\TestCase;

class IcalTextTest extends TestCase
{
    public function test_it_escapes_backslashes_semicolons_commas_and_newlines(): void
    {
        $this->assertSame('a\\\\b\\;c\\,d\\ne\\nf', IcalText::escape("a\\b;c,d\ne\r\nf"));
    }

    public function test_a_short_line_is_left_alone(): void
    {
        $line = str_repeat('a', 75);

        $this->assertSame($line, IcalText::fold($line));
    }

    public function test_a_long_line_is_folded_to_75_octets_including_the_continuation_space(): void
    {
        $line = 'DESCRIPTION:'.str_repeat('x', 400);

        $folded = IcalText::fold($line);

        foreach (explode("\r\n", $folded) as $physical) {
            $this->assertLessThanOrEqual(75, strlen($physical));
        }
        // Dépliée (CRLF + espace retirés), la ligne retrouve son contenu d'origine.
        $this->assertSame($line, str_replace("\r\n ", '', $folded));
    }

    public function test_folding_never_cuts_a_multibyte_character(): void
    {
        $line = 'SUMMARY:'.str_repeat('勤怠シフト', 40).str_repeat('é', 40);

        $folded = IcalText::fold($line);

        foreach (explode("\r\n", $folded) as $physical) {
            $this->assertLessThanOrEqual(75, strlen($physical));
            $this->assertTrue(mb_check_encoding($physical, 'UTF-8'), 'A physical line is not valid UTF-8.');
        }
        $this->assertSame($line, str_replace("\r\n ", '', $folded));
    }
}

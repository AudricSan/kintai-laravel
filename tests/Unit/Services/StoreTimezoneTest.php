<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\IcalService;
use App\Services\StoreTimezone;
use Illuminate\Translation\Translator;
use App\Validation\StoreValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fuseau d'un magasin : « UTC+9 » saisi à la main n'est pas un fuseau pour Google ni pour PHP, ce qui plaçait les événements en
 * UTC (+9 h à Tokyo : un shift de 06:00 à 15:00 s'affichait de 15:00 à minuit).
 */
final class StoreTimezoneTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function values(): array
    {
        return [
            'identifiant valide'          => ['Asia/Tokyo', 'Asia/Tokyo'],
            'casse différente'            => ['asia/tokyo', 'Asia/Tokyo'],
            'UTC+9 (le cas réel)'         => ['UTC+9', 'Asia/Tokyo'],
            'UTC +09:00'                  => ['UTC +09:00', 'Asia/Tokyo'],
            'GMT+9'                       => ['GMT+9', 'Asia/Tokyo'],
            'décalage seul'               => ['+09:00', 'Asia/Tokyo'],
            'JST'                         => ['jst', 'Asia/Tokyo'],
            'UTC-5 (signe inversé Etc)'   => ['UTC-5', 'Etc/GMT+5'],
            'UTC+2'                       => ['UTC+2', 'Etc/GMT-2'],
            'UTC+0'                       => ['UTC+0', 'UTC'],
            'demi-heure'                  => ['UTC+5:30', '+05:30'],
            'UTC seul'                    => ['UTC', 'UTC'],
            'hors limites'                => ['UTC+25', null],
            'texte inconnu'               => ['Atlantide', null],
            'vide'                        => ['', null],
        ];
    }

    #[DataProvider('values')]
    public function testNormalize(string $input, ?string $expected): void
    {
        $this->assertSame($expected, StoreTimezone::normalize($input));
    }

    public function testEveryNormalisedValueIsUsableByPhp(): void
    {
        foreach (self::values() as [$input, $expected]) {
            if ($expected !== null) {
                $this->assertInstanceOf(\DateTimeZone::class, new \DateTimeZone($expected), $input);
            }
        }
    }

    public function testResolveNeverReturnsAnInvalidIdentifier(): void
    {
        $this->assertSame('Asia/Tokyo', StoreTimezone::resolve('UTC+9'));
        $this->assertSame(date_default_timezone_get(), StoreTimezone::resolve('Atlantide'));
        $this->assertSame(date_default_timezone_get(), StoreTimezone::resolve(null));
    }

    public function testTheFormRefusesAnUnknownZoneButAcceptsTheCommonSpelling(): void
    {
        $this->assertTrue(StoreValidator::forUpdate(['timezone' => 'UTC+9'])->passes());
        $this->assertTrue(StoreValidator::forUpdate(['timezone' => 'Europe/Paris'])->passes());
        $this->assertTrue(StoreValidator::forUpdate([])->passes(), 'champ absent : repli sur le fuseau de l’instance');
        $this->assertTrue(StoreValidator::forUpdate(['timezone' => 'Atlantide'])->fails());
    }

    public function testTheIcalFeedHonoursAStoreSavedAsUtcPlusNine(): void
    {
        $translations = kintai_test_real_translator();
        $shift = ['id' => 1, 'shift_date' => '2026-11-02', 'start_time' => '06:00', 'end_time' => '15:00'];

        $legacy = (new IcalService($translations))->build([$shift], [], ['name' => 'Shibuya', 'timezone' => 'UTC+9'], ['language' => 'fr']);
        $tokyo  = (new IcalService($translations))->build([$shift], [], ['name' => 'Shibuya', 'timezone' => 'Asia/Tokyo'], ['language' => 'fr']);

        // 06:00 à Tokyo = 21:00 UTC la veille ; avant le correctif, « UTC+9 » retombait en UTC (06:00Z, soit 15:00 à Tokyo).
        $this->assertStringContainsString('DTSTART:20261101T210000Z', $legacy);
        $this->assertStringContainsString('DTEND:20261102T060000Z', $legacy);
        $this->assertStringContainsString('X-WR-TIMEZONE:Asia/Tokyo', $legacy);
        $strip = static fn(string $ics): string => (string) preg_replace('/^(DTSTAMP|CREATED|LAST-MODIFIED):.*\r?\n/m', '', $ics);
        $this->assertSame($strip($tokyo), $strip($legacy));
    }
}

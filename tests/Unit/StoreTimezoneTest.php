<?php

namespace Tests\Unit;

use App\Support\StoreTimezone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreTimezoneTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function conversions(): array
    {
        return [
            'iana identifier kept' => ['Europe/Paris', 'Europe/Paris'],
            'other casing' => ['asia/tokyo', 'Asia/Tokyo'],
            'surrounding spaces' => ['  Asia/Tokyo ', 'Asia/Tokyo'],
            'JST' => ['JST', 'Asia/Tokyo'],
            'lowercase jst' => ['jst', 'Asia/Tokyo'],
            'KST' => ['KST', 'Asia/Seoul'],
            'CET' => ['CET', 'Europe/Paris'],
            'GMT' => ['GMT', 'UTC'],
            'UTC+9' => ['UTC+9', 'Asia/Tokyo'],
            'UTC +09:00' => ['UTC +09:00', 'Asia/Tokyo'],
            '+09:00' => ['+09:00', 'Asia/Tokyo'],
            'GMT-5' => ['GMT-5', 'Etc/GMT+5'],
            'UTC+1' => ['UTC+1', 'Etc/GMT-1'],
            'UTC+0' => ['UTC+0', 'UTC'],
            'half hour offset' => ['+05:30', '+05:30'],
            'offset too large' => ['UTC+15', null],
            'invalid minutes' => ['+09:75', null],
            'unknown text' => ['Mars/Olympus', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('conversions')]
    public function test_normalize(string $input, ?string $expected): void
    {
        $this->assertSame($expected, StoreTimezone::normalize($input));
    }

    public function test_null_is_not_recognized(): void
    {
        $this->assertNull(StoreTimezone::normalize(null));
    }

    public function test_resolve_falls_back_to_the_app_timezone_when_empty(): void
    {
        config(['app.timezone' => 'Europe/Paris']);

        $this->assertSame('Europe/Paris', StoreTimezone::resolve(''));
        $this->assertSame('Europe/Paris', StoreTimezone::resolve(null));
        $this->assertSame('Asia/Tokyo', StoreTimezone::resolve('JST'));
        $this->assertNull(StoreTimezone::resolve('nonsense'));
    }
}

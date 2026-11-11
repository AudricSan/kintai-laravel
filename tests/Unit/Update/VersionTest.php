<?php

namespace Tests\Unit\Update;

use App\Update\UpdateChannel;
use App\Update\Version;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function test_parses_with_and_without_v_prefix(): void
    {
        $this->assertSame('1.2.3', (string) Version::parse('v1.2.3'));
        $this->assertSame('1.2.3-beta.2', (string) Version::parse('1.2.3-beta.2'));
        $this->assertSame('1.0.0-rc.1', (string) Version::parse('v1.0.0-rc.1'));
    }

    #[DataProvider('invalid')]
    public function test_invalid_input_throws(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Version::parse($input);
    }

    /** @return array<string, array{string}> */
    public static function invalid(): array
    {
        return ['empty' => [''], 'two parts' => ['1.2'], 'text' => ['latest'], 'unknown pre' => ['1.0.0-snapshot.1'], 'no pre number' => ['1.0.0-beta'], 'metadata' => ['1.0.0+build'], 'four parts' => ['1.2.3.4']];
    }

    public function test_try_parse_returns_null_when_invalid(): void
    {
        $this->assertNull(Version::tryParse('nope'));
        $this->assertNotNull(Version::tryParse('2.0.0'));
    }

    public function test_semver_ordering(): void
    {
        $ordered = ['0.9.9', '1.0.0-alpha.1', '1.0.0-alpha.2', '1.0.0-beta.1', '1.0.0-beta.10', '1.0.0-rc.1', '1.0.0', '1.0.1', '1.10.0', '2.0.0'];

        for ($i = 1; $i < count($ordered); $i++) {
            $this->assertTrue(Version::parse($ordered[$i])->isNewerThan(Version::parse($ordered[$i - 1])), $ordered[$i].' > '.$ordered[$i - 1]);
            $this->assertFalse(Version::parse($ordered[$i - 1])->isNewerThan(Version::parse($ordered[$i])));
        }

        $this->assertFalse(Version::parse('v1.0.0')->isNewerThan(Version::parse('1.0.0')));
        $this->assertSame(0, Version::parse('v1.0.0')->compare(Version::parse('1.0.0')));
    }

    public function test_channel(): void
    {
        $this->assertSame('release', Version::parse('1.0.0')->channel());
        $this->assertSame('beta', Version::parse('1.0.0-beta.1')->channel());
        $this->assertSame('beta', Version::parse('1.0.0-rc.1')->channel());
        $this->assertSame('alpha', Version::parse('1.0.0-alpha.1')->channel());
    }

    public function test_update_channel_accepts(): void
    {
        $stable = Version::parse('1.0.0');
        $beta = Version::parse('1.0.0-beta.1');
        $rc = Version::parse('1.0.0-rc.1');
        $alpha = Version::parse('1.0.0-alpha.1');

        $this->assertSame([true, false, false, false], array_map(fn (Version $v) => UpdateChannel::Release->accepts($v), [$stable, $beta, $rc, $alpha]));
        $this->assertSame([true, true, true, false], array_map(fn (Version $v) => UpdateChannel::Beta->accepts($v), [$stable, $beta, $rc, $alpha]));
        $this->assertSame([true, true, true, true], array_map(fn (Version $v) => UpdateChannel::Alpha->accepts($v), [$stable, $beta, $rc, $alpha]));
    }
}

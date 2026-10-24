<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\WidgetGuard;
use PHPUnit\Framework\TestCase;

/** Un widget de bundle défaillant ne doit jamais faire tomber la page qui l'affiche. */
final class WidgetGuardTest extends TestCase
{
    public function testReturnsTheLoadedValue(): void
    {
        $this->assertSame([1, 2], WidgetGuard::run('demo', fn() => [1, 2]));
    }

    public function testReturnsTheFallbackWhenTheLoaderFails(): void
    {
        $this->assertSame([], WidgetGuard::run('demo', function (): array {
            throw new \RuntimeException('no such table');
        }));
        $this->assertNull(WidgetGuard::run('demo', function () {
            throw new \RuntimeException('no such table');
        }, null));
    }

    public function testErrorsAreNotSwallowedWhenTheLoaderSucceedsWithFalsyValues(): void
    {
        $this->assertSame(0, WidgetGuard::run('demo', fn() => 0, 99));
    }
}

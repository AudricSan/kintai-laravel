<?php

namespace Tests\Unit\Navigation;

use App\Navigation\DefaultNavigationRegistry;
use InvalidArgumentException;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use PHPUnit\Framework\TestCase;

class NavigationRegistryTest extends TestCase
{
    public function test_duplicate_keys_are_refused(): void
    {
        $registry = new DefaultNavigationRegistry;
        $registry->register(new NavigationItem('a', 'A', 'a.index', 'home', NavigationRegistry::SECTION_PLANNING));

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new NavigationItem('a', 'Other', 'b.index', 'home', NavigationRegistry::SECTION_HR));
    }

    public function test_unknown_sections_are_refused(): void
    {
        $registry = new DefaultNavigationRegistry;

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new NavigationItem('a', 'A', 'a.index', 'home', 'nowhere'));
    }

    public function test_registered_keys_are_known(): void
    {
        $registry = new DefaultNavigationRegistry;
        $registry->register(new NavigationItem('a', 'A', 'a.index', 'home', NavigationRegistry::SECTION_MAIN));

        $this->assertTrue($registry->has('a'));
        $this->assertFalse($registry->has('b'));
    }

    public function test_sections_are_declared_in_display_order(): void
    {
        $this->assertSame(
            ['main', 'planning', 'requests', 'reports', 'hr', 'system', 'account'],
            array_keys(NavigationRegistry::SECTIONS),
        );
    }
}

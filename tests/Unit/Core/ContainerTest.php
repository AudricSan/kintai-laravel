<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use kintai\Core\Container;

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testBindReturnsNewInstanceEachCall(): void
    {
        $this->container->bind('counter', fn() => new \stdClass());

        $a = $this->container->make('counter');
        $b = $this->container->make('counter');

        $this->assertNotSame($a, $b);
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $this->container->singleton('single', fn() => new \stdClass());

        $a = $this->container->make('single');
        $b = $this->container->make('single');

        $this->assertSame($a, $b);
    }

    public function testInstanceReturnsExactObject(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $this->container->instance('obj', $obj);

        $this->assertSame($obj, $this->container->make('obj'));
    }

    public function testHasReturnsTrueForRegistered(): void
    {
        $this->container->bind('a', fn() => new \stdClass());
        $this->container->singleton('b', fn() => new \stdClass());
        $this->container->instance('c', new \stdClass());

        $this->assertTrue($this->container->has('a'));
        $this->assertTrue($this->container->has('b'));
        $this->assertTrue($this->container->has('c'));
        $this->assertFalse($this->container->has('d'));
    }

    public function testAutoResolveSimpleClass(): void
    {
        $obj = $this->container->make(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $obj);
    }

    public function testThrowsForNonExistentClass(): void
    {
        $this->expectException(RuntimeException::class);
        $this->container->make('NonExistentClass');
    }
}

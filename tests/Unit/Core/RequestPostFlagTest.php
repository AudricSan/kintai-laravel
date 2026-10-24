<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Request;
use PHPUnit\Framework\TestCase;

/** Audit du 13/10/2026 (M12) : une case absente de la requête garde sa valeur au lieu de valoir « décoché ». */
final class RequestPostFlagTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
    }

    private function request(array $post): Request
    {
        $_POST = $post;
        return new Request();
    }

    public function testAbsentFieldKeepsTheCurrentValue(): void
    {
        $req = $this->request([]);

        $this->assertFalse($req->hasPost('is_active'));
        $this->assertSame(1, $req->postFlag('is_active', 1));
        $this->assertSame(0, $req->postFlag('is_active', 0));
        $this->assertSame(1, $req->postFlag('is_active', true));
    }

    public function testHiddenZeroMeansUnchecked(): void
    {
        $req = $this->request(['is_active' => '0']);

        $this->assertTrue($req->hasPost('is_active'));
        $this->assertSame(0, $req->postFlag('is_active', 1));
    }

    /** @return array<string, array{mixed}> */
    public static function checkedValues(): array
    {
        return ['1' => ['1'], 'on' => ['on'], 'true' => ['true']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('checkedValues')]
    public function testCheckedValues(mixed $value): void
    {
        $this->assertSame(1, $this->request(['flag' => $value])->postFlag('flag', 0));
    }

    public function testAnEmptyStringCountsAsPresentAndUnchecked(): void
    {
        $this->assertSame(0, $this->request(['flag' => ''])->postFlag('flag', 1));
    }
}

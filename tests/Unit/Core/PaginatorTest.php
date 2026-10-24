<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Api\Paginator;
use kintai\Core\Request;
use PHPUnit\Framework\TestCase;

final class PaginatorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // paginate()
    // -------------------------------------------------------------------------

    public function testPaginateReturnsFirstPage(): void
    {
        $items  = range(1, 10);
        $result = Paginator::paginate($items, 1, 3);

        $this->assertSame([1, 2, 3], $result['data']);
        $this->assertSame(10, $result['meta']['total']);
        $this->assertSame(1, $result['meta']['page']);
        $this->assertSame(3, $result['meta']['limit']);
        $this->assertSame(4, $result['meta']['pages']);
    }

    public function testPaginateReturnsMiddlePage(): void
    {
        $items  = range(1, 10);
        $result = Paginator::paginate($items, 2, 3);

        $this->assertSame([4, 5, 6], $result['data']);
        $this->assertSame(2, $result['meta']['page']);
    }

    public function testPaginateLastPageIsPartial(): void
    {
        $items  = range(1, 10);
        $result = Paginator::paginate($items, 4, 3);

        $this->assertSame([10], $result['data']);
        $this->assertSame(4, $result['meta']['pages']);
    }

    public function testPaginatePageBeyondTotalReturnsEmpty(): void
    {
        $items  = range(1, 5);
        $result = Paginator::paginate($items, 99, 10);

        $this->assertSame([], $result['data']);
        $this->assertSame(5, $result['meta']['total']);
    }

    public function testPaginateEmptyItemsReturnsEmptyData(): void
    {
        $result = Paginator::paginate([], 1, 20);

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['meta']['total']);
        $this->assertSame(0, $result['meta']['pages']);
    }

    public function testPaginateLimitExactDivision(): void
    {
        $items  = range(1, 9);
        $result = Paginator::paginate($items, 1, 3);

        $this->assertSame(3, $result['meta']['pages']);
    }

    public function testPaginateLimitWithRemainder(): void
    {
        $items  = range(1, 10);
        $result = Paginator::paginate($items, 1, 3);

        $this->assertSame(4, $result['meta']['pages']);
    }

    public function testPaginateLimit1(): void
    {
        $items  = ['a', 'b', 'c'];
        $result = Paginator::paginate($items, 2, 1);

        $this->assertSame(['b'], $result['data']);
        $this->assertSame(3, $result['meta']['pages']);
    }

    public function testPaginateResultIsReindexed(): void
    {
        $items  = [10 => 'a', 20 => 'b', 30 => 'c'];
        $result = Paginator::paginate(array_values($items), 1, 2);

        $this->assertSame([0, 1], array_keys($result['data']));
    }

    public function testPaginateMetaKeysPresent(): void
    {
        $result = Paginator::paginate([1, 2], 1, 10);

        $this->assertArrayHasKey('total', $result['meta']);
        $this->assertArrayHasKey('page', $result['meta']);
        $this->assertArrayHasKey('limit', $result['meta']);
        $this->assertArrayHasKey('pages', $result['meta']);
    }

    // -------------------------------------------------------------------------
    // params()
    // -------------------------------------------------------------------------

    private function makeRequest(array $get = []): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/test', 'SCRIPT_NAME' => '/index.php'];
        $_GET    = $get;
        $_POST   = [];
        $_COOKIE = [];
        $_FILES  = [];
        return new Request();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
    }

    public function testParamsDefaultsToPage1Limit20(): void
    {
        $req = $this->makeRequest();
        [$page, $limit] = Paginator::params($req);

        $this->assertSame(1, $page);
        $this->assertSame(20, $limit);
    }

    public function testParamsReadsPageAndLimit(): void
    {
        $req = $this->makeRequest(['page' => '3', 'limit' => '50']);
        [$page, $limit] = Paginator::params($req);

        $this->assertSame(3, $page);
        $this->assertSame(50, $limit);
    }

    public function testParamsClampPageToMinimum1(): void
    {
        $req = $this->makeRequest(['page' => '0']);
        [$page,] = Paginator::params($req);

        $this->assertSame(1, $page);
    }

    public function testParamsClampNegativePage(): void
    {
        $req = $this->makeRequest(['page' => '-5']);
        [$page,] = Paginator::params($req);

        $this->assertSame(1, $page);
    }

    public function testParamsClampLimitToMax200(): void
    {
        $req = $this->makeRequest(['limit' => '999']);
        [, $limit] = Paginator::params($req);

        $this->assertSame(200, $limit);
    }

    public function testParamsClampLimitToMinimum1(): void
    {
        $req = $this->makeRequest(['limit' => '0']);
        [, $limit] = Paginator::params($req);

        $this->assertSame(1, $limit);
    }
}

<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Routing;

use kintai\Core\Router;
use kintai\Core\Routing\RouteSlugService;
use PHPUnit\Framework\TestCase;

/** Attribution des alias de magasins : nom tel quel par défaut, slug manuel prioritaire, historique conservé. */
final class RouteSlugServiceTest extends TestCase
{
    private InMemoryRouteSlugRepository $repo;
    private RouteSlugService $service;

    protected function setUp(): void
    {
        $router = new Router();
        $router->get('/admin/stores/create', ['C', 'create']);
        $router->get('/admin/stores/{id:store}/edit', ['C', 'edit']);
        $router->get('/admin/stores/{id:store}/profitability', ['C', 'profit']);

        $this->repo    = new InMemoryRouteSlugRepository();
        $this->service = new RouteSlugService($this->repo, $router);
    }

    private function history(int $storeId): array
    {
        return array_values(array_map(
            fn(array $r) => $r['slug'] . ($r['is_current'] ? ' (courant)' : ''),
            array_filter($this->repo->rows, fn(array $r) => $r['entity_id'] === $storeId),
        ));
    }

    public function testNewStoreGetsItsNameAsAlias(): void
    {
        $this->service->syncStore(3, '所沢寿町店', null);

        $this->assertSame(['所沢寿町店 (courant)'], $this->history(3));
    }

    public function testRenamingMovesTheOldAliasToHistory(): void
    {
        $this->service->syncStore(3, '所沢寿町店', '');
        $this->service->syncStore(3, '所沢寿町店（新）', '');

        $this->assertSame(['所沢寿町店', '所沢寿町店-新 (courant)'], $this->history(3));
    }

    public function testSavingWithoutRenamingChangesNothing(): void
    {
        $this->service->syncStore(3, '所沢寿町店', '');
        $this->service->syncStore(3, '所沢寿町店', '');

        $this->assertSame(['所沢寿町店 (courant)'], $this->history(3));
    }

    public function testManualSlugWinsAndSurvivesARename(): void
    {
        $this->service->syncStore(3, '所沢寿町店', '');
        $this->service->syncStore(3, '所沢寿町店', 'tokorozawa-kotobukicho');
        // Renommage par l'API (champ slug absent) : le slug manuel reste.
        $this->service->syncStore(3, '所沢寿町店（新）', null);

        $this->assertSame(['所沢寿町店', 'tokorozawa-kotobukicho (courant)'], $this->history(3));
    }

    public function testClearingTheManualSlugFallsBackToTheName(): void
    {
        $this->service->syncStore(3, '所沢寿町店', 'tokorozawa-kotobukicho');
        $this->service->syncStore(3, '所沢寿町店', '');

        $this->assertSame(['tokorozawa-kotobukicho', '所沢寿町店 (courant)'], $this->history(3));
    }

    public function testHomonymsGetASuffix(): void
    {
        $this->service->syncStore(1, 'Shibuya', '');
        $this->service->syncStore(2, 'Shibuya', '');

        $this->assertSame(['shibuya (courant)'], $this->history(1));
        $this->assertSame(['shibuya-2 (courant)'], $this->history(2));
    }

    public function testAnAliasOnceUsedByAnotherStoreIsNotReassignedAutomatically(): void
    {
        $this->service->syncStore(1, 'Shibuya', '');
        $this->service->syncStore(1, 'Ebisu', '');      // « shibuya » reste dans l'historique du magasin 1
        $this->service->syncStore(2, 'Shibuya', '');

        $this->assertSame(['shibuya-2 (courant)'], $this->history(2));
    }

    public function testFixedRouteSegmentsAreReservedAutomatically(): void
    {
        $this->service->syncStore(4, 'Create', '');
        $this->service->syncStore(5, 'Profitability', '');

        $this->assertSame(['create-2 (courant)'], $this->history(4));
        $this->assertSame(['profitability-2 (courant)'], $this->history(5));
    }

    public function testManualSlugErrors(): void
    {
        $this->service->syncStore(1, 'Shibuya', 'shibuya-center');

        $this->assertSame('store_slug_invalid', $this->service->manualStoreSlugError('Pas Bon', 2));
        $this->assertSame('store_slug_reserved', $this->service->manualStoreSlugError('create', 2));
        $this->assertSame('store_slug_taken', $this->service->manualStoreSlugError('shibuya-center', 2));
        $this->assertNull($this->service->manualStoreSlugError('shibuya-center', 1), 'son propre slug reste acceptable');
        $this->assertNull($this->service->manualStoreSlugError('ebisu', 2));
    }
}

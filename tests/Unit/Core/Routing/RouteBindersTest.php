<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Routing;

use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Routing\EmployeeRouteBinder;
use kintai\Core\Routing\StoreRouteBinder;
use PHPUnit\Framework\TestCase;

/** Segment d'URL ↔ identifiant : magasins par alias, employés par numéro d'employé (jamais par nom). */
final class RouteBindersTest extends TestCase
{
    private function storeBinder(InMemoryRouteSlugRepository $repo, array $existingIds = [1, 2, 3]): StoreRouteBinder
    {
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findById')->willReturnCallback(fn(int $id) => in_array($id, $existingIds, true) ? ['id' => $id] : null);

        return new StoreRouteBinder($repo, $stores);
    }

    private function storeRepo(): InMemoryRouteSlugRepository
    {
        $repo = new InMemoryRouteSlugRepository();
        $repo->setCurrent('store', 1, '所沢東町店', false);
        $repo->setCurrent('store', 2, 'old-name', true);
        $repo->setCurrent('store', 2, 'tokorozawa-kotobukicho', true); // old-name passe en historique
        return $repo;
    }

    public function testStoreCurrentAliasIsCanonical(): void
    {
        $binder = $this->storeBinder($this->storeRepo());

        $bound = $binder->resolve('所沢東町店');
        $this->assertSame(1, $bound?->id);
        $this->assertTrue($bound->canonical);
        $this->assertSame('所沢東町店', $binder->segmentFor(1));
    }

    public function testStoreOldLinksResolveButAreNotCanonical(): void
    {
        $binder = $this->storeBinder($this->storeRepo());

        foreach (['old-name' => 2, '2' => 2, 'TOKOROZAWA-KOTOBUKICHO' => 2, '1' => 1] as $segment => $id) {
            $bound = $binder->resolve((string) $segment);
            $this->assertSame($id, $bound?->id, "« $segment »");
            $this->assertFalse($bound->canonical, "« $segment » doit être redirigé");
        }
    }

    public function testStoreWithoutAliasKeepsItsIdAsCanonicalSegment(): void
    {
        $binder = $this->storeBinder($this->storeRepo());

        $bound = $binder->resolve('3');
        $this->assertSame(3, $bound?->id);
        $this->assertTrue($bound->canonical);
        $this->assertSame('3', $binder->segmentFor(3));
    }

    public function testUnknownStoreSegmentResolvesToNothing(): void
    {
        $binder = $this->storeBinder($this->storeRepo());

        $this->assertNull($binder->resolve('nope'));
        $this->assertNull($binder->resolve('999'));
    }

    private function employeeBinder(): EmployeeRouteBinder
    {
        $repo = new InMemoryRouteSlugRepository([18 => '016', 26 => null, 100 => '777', 5 => '100']);
        $repo->addHistory('employee', 18, '015');

        return new EmployeeRouteBinder($repo);
    }

    public function testEmployeeNumberIsCanonical(): void
    {
        $binder = $this->employeeBinder();

        $bound = $binder->resolve('016');
        $this->assertSame(18, $bound?->id);
        $this->assertTrue($bound->canonical);
        $this->assertSame('016', $binder->segmentFor(18));
    }

    public function testEmployeeWithoutNumberUsesPrefixedId(): void
    {
        $binder = $this->employeeBinder();

        $this->assertSame('id-26', $binder->segmentFor(26));
        $bound = $binder->resolve('id-26');
        $this->assertSame(26, $bound?->id);
        $this->assertTrue($bound->canonical);
    }

    public function testEmployeeOldLinksResolveButAreNotCanonical(): void
    {
        $binder = $this->employeeBinder();

        foreach (['18' => 18, '015' => 18, 'id-18' => 18, '26' => 26, 'ID-26' => 26] as $segment => $id) {
            $bound = $binder->resolve((string) $segment);
            $this->assertSame($id, $bound?->id, "« $segment »");
            $this->assertFalse($bound->canonical, "« $segment » doit être redirigé");
        }
    }

    public function testEmployeeNumberWinsOverAnIdenticalId(): void
    {
        // « 100 » est à la fois le numéro de l'employé 5 et l'identifiant de l'employé 100 : le numéro l'emporte.
        $bound = $this->employeeBinder()->resolve('100');
        $this->assertSame(5, $bound?->id);
        $this->assertTrue($bound->canonical);
    }

    public function testUnknownEmployeeSegmentResolvesToNothing(): void
    {
        $binder = $this->employeeBinder();

        $this->assertNull($binder->resolve('999999'));
        $this->assertNull($binder->resolve('id-999'));
        $this->assertNull($binder->resolve('dupont'));
    }

    /** @return array<string, array{string, bool}> */
    public static function employeeCodes(): array
    {
        return [
            'numérique'          => ['057', true],
            'alphanumérique'     => ['EMP-12_A', true],
            'barre oblique'      => ['A/B', false],
            'espace'             => ['A B', false],
            'préfixe réservé'    => ['ID-42', false],
            'préfixe minuscules' => ['id-42', false],
            'japonais'           => ['山田', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('employeeCodes')]
    public function testEmployeeCodeMustBeUsableInAnUrl(string $code, bool $valid): void
    {
        $this->assertSame($valid, EmployeeRouteBinder::isValidCode($code));
    }

    /** Audit M8 : un magasin supprimé (suppression douce) n'est plus joignable, même par son identifiant. */
    public function testADeletedStoreNoLongerResolves(): void
    {
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findById')->willReturnCallback(
            fn(int $id) => $id === 4 ? ['id' => 4, 'deleted_at' => '2026-10-16 09:00:00'] : null
        );
        $binder = new StoreRouteBinder(new InMemoryRouteSlugRepository(), $stores);

        $this->assertNull($binder->resolve('4'));
    }
}

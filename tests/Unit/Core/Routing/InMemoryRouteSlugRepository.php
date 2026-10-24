<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Routing;

use kintai\Core\Repositories\RouteSlugRepositoryInterface;

/** Double en mémoire de la table route_slugs (et des numéros d'employé), pour les tests du routage lisible. */
final class InMemoryRouteSlugRepository implements RouteSlugRepositoryInterface
{
    /** @var list<array{entity_type: string, entity_id: int, slug: string, is_current: bool, is_manual: bool}> */
    public array $rows = [];

    /** @param array<int, string|null> $employeeCodes */
    public function __construct(public array $employeeCodes = []) {}

    public function currentSlugs(string $type): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if ($r['entity_type'] === $type && $r['is_current']) {
                $out[$r['entity_id']] = $r['slug'];
            }
        }
        return $out;
    }

    public function current(string $type, int $entityId): ?array
    {
        foreach ($this->rows as $r) {
            if ($r['entity_type'] === $type && $r['entity_id'] === $entityId && $r['is_current']) {
                return $this->public($r);
            }
        }
        return null;
    }

    public function findBySlug(string $type, string $slug): ?array
    {
        foreach ($this->rows as $r) {
            if ($r['entity_type'] === $type && $r['slug'] === $slug) {
                return $this->public($r);
            }
        }
        return null;
    }

    public function isTakenByOther(string $type, string $slug, int $exceptEntityId): bool
    {
        foreach ($this->rows as $r) {
            if ($r['entity_type'] === $type && $r['slug'] === $slug && $r['entity_id'] !== $exceptEntityId) {
                return true;
            }
        }
        return false;
    }

    public function setCurrent(string $type, int $entityId, string $slug, bool $manual): void
    {
        $this->releaseFromOthers($type, $slug, $entityId);
        $found = false;
        foreach ($this->rows as &$r) {
            if ($r['entity_type'] !== $type || $r['entity_id'] !== $entityId) {
                continue;
            }
            if ($r['slug'] === $slug) {
                $r['is_current'] = true;
                $r['is_manual']  = $manual;
                $found = true;
            } else {
                $r['is_current'] = false;
            }
        }
        unset($r);
        if (!$found) {
            $this->rows[] = ['entity_type' => $type, 'entity_id' => $entityId, 'slug' => $slug, 'is_current' => true, 'is_manual' => $manual];
        }
    }

    public function addHistory(string $type, int $entityId, string $slug): void
    {
        if ($this->findBySlug($type, $slug) === null) {
            $this->rows[] = ['entity_type' => $type, 'entity_id' => $entityId, 'slug' => $slug, 'is_current' => false, 'is_manual' => false];
        }
    }

    public function releaseFromOthers(string $type, string $slug, int $entityId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            fn(array $r): bool => !($r['entity_type'] === $type && $r['slug'] === $slug && $r['entity_id'] !== $entityId),
        ));
    }

    public function deleteForEntity(string $type, int $entityId): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            fn(array $r): bool => !($r['entity_type'] === $type && $r['entity_id'] === $entityId),
        ));
    }

    public function employeeCodes(): array
    {
        return $this->employeeCodes;
    }

    /** @return array{entity_id: int, slug: string, is_current: bool, is_manual: bool} */
    private function public(array $r): array
    {
        return ['entity_id' => $r['entity_id'], 'slug' => $r['slug'], 'is_current' => $r['is_current'], 'is_manual' => $r['is_manual']];
    }
}

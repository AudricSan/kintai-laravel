<?php

declare(strict_types=1);

namespace kintai\Core\Routing;

use kintai\Core\Repositories\RouteSlugRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;

/**
 * Magasin ↔ segment d'URL : son alias courant (slug saisi à la main, sinon son nom, ex. 所沢東町店).
 * Un magasin sans alias enregistré garde son identifiant comme segment canonique.
 */
final class StoreRouteBinder implements RouteParamBinder
{
    /** @var array<int, string>|null */
    private ?array $current = null;

    public function __construct(
        private readonly RouteSlugRepositoryInterface $slugs,
        private readonly StoreRepositoryInterface $stores,
    ) {}

    public function resolve(string $segment): ?BoundParam
    {
        $current = $this->current();

        $id = array_search($segment, $current, true);
        if ($id !== false) {
            return new BoundParam((int) $id, true);
        }

        $lower = mb_strtolower($segment, 'UTF-8');
        if ($lower !== $segment && ($id = array_search($lower, $current, true)) !== false) {
            return new BoundParam((int) $id, false);
        }

        try {
            $row = $this->slugs->findBySlug(RouteSlugRepositoryInterface::TYPE_STORE, $segment)
                ?? ($lower !== $segment ? $this->slugs->findBySlug(RouteSlugRepositoryInterface::TYPE_STORE, $lower) : null);
        } catch (\Illuminate\Database\QueryException) {
            $row = null; // Table route_slugs absente (migration pas encore jouée) : pas d'historique.
        }
        if ($row !== null) {
            return new BoundParam($row['entity_id'], false);
        }

        // Ancien lien par identifiant numérique.
        $store = ctype_digit($segment) ? $this->stores->findById((int) $segment) : null;
        if ($store !== null && empty($store['deleted_at'])) {
            $id = (int) $segment;
            return new BoundParam($id, !isset($current[$id]));
        }

        return null;
    }

    public function segmentFor(int $id): string
    {
        return $this->current()[$id] ?? (string) $id;
    }

    /** Oublie le cache (après un renommage dans la même requête). */
    public function reset(): void
    {
        $this->current = null;
    }

    /** @return array<int, string> */
    private function current(): array
    {
        if ($this->current === null) {
            try {
                $this->current = $this->slugs->currentSlugs(RouteSlugRepositoryInterface::TYPE_STORE);
            } catch (\Illuminate\Database\QueryException) {
                // Table route_slugs absente (migration pas encore jouée) : les identifiants restent canoniques.
                $this->current = [];
            }
        }

        return $this->current;
    }
}

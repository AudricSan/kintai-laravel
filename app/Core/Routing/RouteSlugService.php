<?php

declare(strict_types=1);

namespace kintai\Core\Routing;

use kintai\Core\Repositories\RouteSlugRepositoryInterface;
use kintai\Core\Router;

/**
 * Attribution des alias d'URL des magasins.
 *
 * - Slug saisi à la main (romaji, ex. tokorozawa-higashicho) : prioritaire, jamais écrasé par un renommage.
 * - Sinon alias automatique tiré du nom, sans translittération (所沢東町店), recalculé à chaque renommage.
 * Tout alias remplacé reste en historique : les anciens liens sont redirigés en 301.
 */
final class RouteSlugService
{
    private const TYPE = RouteSlugRepositoryInterface::TYPE_STORE;

    /** @var list<string>|null */
    private ?array $reserved = null;

    public function __construct(
        private readonly RouteSlugRepositoryInterface $slugs,
        private readonly Router $router,
        private readonly ?RouteBinderRegistry $binders = null,
    ) {}

    /**
     * Clé de traduction de l'erreur si le slug saisi pour ce magasin est refusé, null s'il est acceptable.
     * $storeId vaut 0 pour un magasin pas encore créé.
     */
    public function manualStoreSlugError(string $slug, int $storeId): ?string
    {
        if (!SlugGenerator::isValidManual($slug)) {
            return 'store_slug_invalid';
        }
        if ($this->isReserved($slug)) {
            return 'store_slug_reserved';
        }
        if ($this->slugs->isTakenByOther(self::TYPE, $slug, $storeId)) {
            return 'store_slug_taken';
        }

        return null;
    }

    /**
     * Met à jour l'alias d'un magasin après création ou modification.
     *
     * @param string|null $manual slug saisi (validé au préalable) ; '' = pas de slug manuel, alias tiré du nom ;
     *                            null = champ non fourni (API) : un slug manuel existant est conservé.
     */
    public function syncStore(int $storeId, string $name, ?string $manual): void
    {
        $current = $this->slugs->current(self::TYPE, $storeId);

        if ($manual !== null && $manual !== '') {
            if ($current === null || $current['slug'] !== $manual || !$current['is_manual']) {
                $this->slugs->setCurrent(self::TYPE, $storeId, $manual, true);
            }
        } elseif ($manual === null && $current !== null && $current['is_manual']) {
            return;
        } else {
            $base = SlugGenerator::fromName($name);
            if ($base === '') {
                $base = 'store-' . $storeId;
            }
            // Alias automatique déjà dérivé de ce nom (éventuellement suffixé) : on n'y touche pas.
            if ($current !== null && !$current['is_manual']
                && ($current['slug'] === $base || preg_match('/^' . preg_quote($base, '/') . '-\d+$/u', $current['slug']) === 1)) {
                return;
            }
            $slug = SlugGenerator::firstFree(
                $base,
                fn(string $c): bool => $this->isReserved($c) || $this->slugs->isTakenByOther(self::TYPE, $c, $storeId),
            );
            $this->slugs->setCurrent(self::TYPE, $storeId, $slug, false);
        }

        $this->binders?->reset();
    }

    /** Alias courant d'un magasin et s'il a été saisi à la main (pour le formulaire). */
    public function currentStoreSlug(int $storeId): ?array
    {
        return $this->slugs->current(self::TYPE, $storeId);
    }

    /**
     * Segments littéraux de toutes les routes enregistrées (create, export, edit…, bundles compris) : un alias
     * identique pourrait masquer une page fixe ou être masqué par elle.
     */
    public function isReserved(string $slug): bool
    {
        if ($this->reserved === null) {
            $words = SlugGenerator::BASE_RESERVED;
            foreach ($this->router->routes() as $route) {
                foreach (explode('/', $route->pattern) as $segment) {
                    if ($segment !== '' && !str_contains($segment, '{')) {
                        $words[] = mb_strtolower($segment, 'UTF-8');
                    }
                }
            }
            $this->reserved = array_values(array_unique($words));
        }

        return in_array(mb_strtolower($slug, 'UTF-8'), $this->reserved, true);
    }
}

<?php

declare(strict_types=1);

namespace kintai\Core\Routing;

/**
 * Résultat de la résolution d'un segment d'URL typé. $canonical vaut false pour un ancien lien (identifiant
 * numérique, alias historique, casse différente) : en GET, l'application redirige alors en 301.
 */
final readonly class BoundParam
{
    public function __construct(
        public int $id,
        public bool $canonical,
    ) {}
}

<?php

declare(strict_types=1);

namespace kintai\Core\Routing;

/**
 * Traduit un paramètre de route typé ({id:store}, {uid:employee}) entre le segment lisible de l'URL et
 * l'identifiant numérique que reçoivent les contrôleurs et les middlewares.
 */
interface RouteParamBinder
{
    /** Segment d'URL (déjà décodé) → entité, ou null si rien ne correspond (404). */
    public function resolve(string $segment): ?BoundParam;

    /** Identifiant → segment d'URL canonique (non encodé). */
    public function segmentFor(int $id): string;
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Foundation\AliasAwareContainer;

/**
 * Conteneur vierge pour les tests qui remplacent le conteneur global (Container::setInstance()) : seules les liaisons
 * que le test déclare existent, comme avant — mais les services du framework (session, redirect, request, config,
 * vues, traducteur…) restent résolus par l'application de test, pour que les helpers de Laravel (session(),
 * redirect(), response()…) continuent de fonctionner.
 */
final class TestContainer extends AliasAwareContainer
{
    public function make($abstract, array $parameters = [])
    {
        if (is_string($abstract) && !$this->bound($abstract) && !str_starts_with($abstract, 'kintai\\')) {
            $app = $GLOBALS['kintai_test_app'] ?? null;
            if ($app !== null && $app->bound($abstract)) {
                return $app->make($abstract, $parameters);
            }
        }

        return parent::make($abstract, $parameters);
    }
}

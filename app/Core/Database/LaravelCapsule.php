<?php

declare(strict_types=1);

namespace kintai\Core\Database;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Capsule (API historique de Kintai et des migrations de bundle) posée sur le gestionnaire de bases de Laravel : une
 * seule connexion pour toute l'application, que le code passe par Capsule, Eloquent ou DB::.
 */
final class LaravelCapsule extends Capsule
{
    public function __construct(Application $app)
    {
        // Pas d'appel au constructeur parent, qui créerait un second gestionnaire (et une seconde connexion).
        $this->container = $app;
        $this->manager   = $app->make('db');
    }
}

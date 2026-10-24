<?php

declare(strict_types=1);

namespace kintai\Foundation;

use Illuminate\Foundation\Application as LaravelApplication;

/**
 * Application Laravel de Kintai.
 *
 * Différences avec celle de Laravel :
 * - le dossier storage/ peut être déplacé par la variable d'environnement KINTAI_STORAGE_PATH, lue à chaque appel (les
 *   tests de sauvegarde et de mise à jour isolent ainsi leur storage/) ;
 * - les alias de classe du contrat de bundle se résolvent vers la classe réelle ({@see ResolvesClassAliases}).
 */
class Application extends LaravelApplication
{
    use ResolvesClassAliases;

    public function storagePath($path = ''): string
    {
        $override = $_ENV['KINTAI_STORAGE_PATH'] ?? $_SERVER['KINTAI_STORAGE_PATH'] ?? getenv('KINTAI_STORAGE_PATH');
        if (is_string($override) && $override !== '') {
            return $this->joinPaths(rtrim($override, '/\\'), $path);
        }

        return parent::storagePath($path);
    }
}

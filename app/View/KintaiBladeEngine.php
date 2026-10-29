<?php

declare(strict_types=1);

namespace App\View;

use Illuminate\View\Engines\CompilerEngine;
use Throwable;

/**
 * Moteur des vues Blade de Kintai : celui de Laravel, sans retirer les blancs de tête de la sortie (le PhpEngine de
 * Laravel les retire). Une vue rend ainsi exactement ce qu'elle rendait en PHP pur.
 */
final class KintaiBladeEngine extends CompilerEngine
{
    protected function evaluatePath($path, $data)
    {
        $obLevel = ob_get_level();

        ob_start();

        try {
            $this->files->getRequire($path, $data);
        } catch (Throwable $e) {
            $this->handleViewException($e, $obLevel);
        }

        return (string) ob_get_clean();
    }
}

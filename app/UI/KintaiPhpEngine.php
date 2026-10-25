<?php

declare(strict_types=1);

namespace kintai\UI;

use Illuminate\View\Engines\PhpEngine;
use Throwable;

/**
 * Moteur des vues PHP de Kintai pour le moteur de vues de Laravel.
 *
 * Rend une vue exactement comme le faisait l'ancien ViewRenderer : mêmes variables dans la vue (y compris pour
 * get_defined_vars()), et sortie gardée telle quelle, là où le PhpEngine de Laravel retire les blancs de tête.
 */
final class KintaiPhpEngine extends PhpEngine
{
    protected function evaluatePath($path, $data)
    {
        $obLevel = ob_get_level();

        ob_start();

        try {
            (static function (string $_file, array $_data) {
                extract($_data, EXTR_SKIP);
                include $_file;
            })($path, $data);
        } catch (Throwable $e) {
            $this->handleViewException($e, $obLevel);
        }

        return (string) ob_get_clean();
    }
}

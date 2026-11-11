<?php

namespace App\Update;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Lance `php artisan kintai:update --finish` dans un NOUVEAU processus PHP : seul lui exécute le code neuf posé par
 * `apply()`. Séparé de la commande pour être remplacé dans les tests.
 *
 * `prepare()` est appelé AVANT `apply()` : il charge les classes dont `run()` a besoin tant que l'ancien code est encore
 * celui du disque.
 */
class FinishProcess
{
    private ?Process $process = null;

    /** Commande à lancer à la main si le sous-processus est impossible. */
    public function manualCommand(): string
    {
        return 'php artisan kintai:update --finish';
    }

    public function prepare(): void
    {
        $this->process = new Process([PHP_BINARY, base_path('artisan'), 'kintai:update', '--finish'], base_path(), null, null, 3600);
    }

    /**
     * @param  callable(string): void  $output  Reçoit la sortie du sous-processus au fil de l'eau.
     * @return int|null Code de sortie, null si le sous-processus n'a pas pu être lancé.
     */
    public function run(callable $output): ?int
    {
        if ($this->process === null) {
            return null;
        }

        try {
            return $this->process->run(static function (string $type, string $buffer) use ($output): void {
                $output($buffer);
            });
        } catch (Throwable) {
            return null;
        }
    }
}

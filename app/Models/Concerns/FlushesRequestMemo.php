<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Database\RequestMemo;

/**
 * Modèle lu au travers de RequestMemo (comptes, rôles, affectations, permissions) : toute écriture vide la mémoire de la
 * requête, avant et après, pour que la lecture suivante voie la base. Sans passer par les événements d'Eloquent, dont
 * le répartiteur change selon le point d'entrée (web, scripts, tests).
 */
trait FlushesRequestMemo
{
    public function save(array $options = []): bool
    {
        RequestMemo::flush();
        try {
            return parent::save($options);
        } finally {
            RequestMemo::flush();
        }
    }

    public function delete(): ?bool
    {
        RequestMemo::flush();
        try {
            return parent::delete();
        } finally {
            RequestMemo::flush();
        }
    }
}

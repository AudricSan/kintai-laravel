<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Créait jusqu'ici les tables de la messagerie (message_threads, thread_participants, thread_messages). Elles
 * appartiennent désormais au bundle messaging (étape 4 du plan du contrat, extraction), qui les crée lui-même à son
 * installation avec le schéma qu'utilise son code. Cette migration ne fait plus rien : une instance existante garde ses
 * tables et ses données, une installation neuve n'a ces tables qu'avec le bundle.
 *
 * Elle reste listée (et non supprimée) pour que les instances qui l'ont déjà jouée ne la voient pas disparaître.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};

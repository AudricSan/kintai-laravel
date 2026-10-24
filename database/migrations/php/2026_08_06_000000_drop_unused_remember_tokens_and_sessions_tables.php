<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * remember_tokens ("remember me" cookie) et sessions (session persistée en BD) n'ont
 * jamais eu la moindre implémentation : aucun modèle Eloquent, aucun repository, aucune
 * lecture/écriture nulle part dans le code — les sessions PHP sont natives, fichier
 * (voir SessionMiddleware), et config/auth.php:'remember_me' n'est lu par rien. Les deux
 * tables ne contiennent donc jamais aucune donnée réelle sur aucune instance existante.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->dropIfExists('remember_tokens');
        $this->schema()->dropIfExists('sessions');
    }

    public function down(): void
    {
        // Tables mortes retirées : rien à recréer (jamais aucune donnée réelle, aucun code ne les utilisait).
    }
};

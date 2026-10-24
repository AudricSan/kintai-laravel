<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Auth\PasswordPolicy;
use kintai\Core\Database\Migration;

/**
 * Jusqu'au 01/10/2026, POST /api/v1/auth/login délivrait un jeton avec le mot de passe par défaut « 0000 »,
 * contournant le changement obligatoire imposé sur le web. Ce refus est désormais en place ; restent les jetons
 * déjà délivrés. On révoque ceux des comptes dont le mot de passe vaut encore « 0000 » : ils pourront en
 * redemander un après avoir changé leur mot de passe sur le web.
 *
 * Un mot de passe court autre que « 0000 » ne peut pas être détecté depuis son hash : ces jetons-là restent
 * valides jusqu'au prochain changement de mot de passe (qui révoque tous les jetons de l'utilisateur).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $schema = $this->schema();
        if (!$schema->hasTable('api_tokens') || !$schema->hasTable('users')) {
            return;
        }

        $db = $this->capsule->getConnection();

        // password_verify() seulement pour les utilisateurs qui ont des jetons : coût borné.
        $userIds = $db->table('api_tokens')->distinct()->pluck('user_id')->all();
        foreach ($db->table('users')->whereIn('id', $userIds)->get(['id', 'password_hash']) as $user) {
            if (password_verify(PasswordPolicy::DEFAULT_PASSWORD, (string) ($user->password_hash ?? ''))) {
                $db->table('api_tokens')->where('user_id', $user->id)->delete();
            }
        }
    }

    public function down(): void
    {
        // Irréversible : un jeton révoqué ne se recrée pas.
    }
};

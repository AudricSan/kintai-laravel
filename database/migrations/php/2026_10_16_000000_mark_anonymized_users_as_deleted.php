<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * La suppression d'un compte anonymise désormais le compte et renseigne deleted_at (UserAnonymizer), ce qui le
 * retire des listes. Les comptes anonymisés avant le 16/10/2026 par la suppression de son propre compte
 * n'avaient pas deleted_at : on le renseigne pour qu'ils disparaissent aussi des listes. Ils se reconnaissent
 * à leur adresse de remplacement « deleted-{id}@kintai.local » et à leur état inactif.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $schema = $this->schema();
        if (!$schema->hasTable('users') || !$schema->hasColumn('users', 'deleted_at')) {
            return;
        }

        $db = $this->capsule->getConnection();
        foreach ($db->table('users')->whereNull('deleted_at')->where('is_active', 0)->where('email', 'like', 'deleted-%@kintai.local')->get(['id', 'email', 'updated_at']) as $user) {
            // Contrôle exact : l'adresse doit être celle de ce compte (pas une adresse saisie qui y ressemblerait).
            if ($user->email === 'deleted-' . $user->id . '@kintai.local') {
                $db->table('users')->where('id', $user->id)->update(['deleted_at' => $user->updated_at ?? date('Y-m-d H:i:s')]);
            }
        }
    }

    public function down(): void
    {
        // Rien à défaire : deleted_at ne fait que refléter une anonymisation déjà faite.
    }
};

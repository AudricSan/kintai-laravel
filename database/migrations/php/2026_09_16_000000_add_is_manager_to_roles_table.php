<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Découple explicitement "ce rôle affiche la navigation manager" de "ce rôle
 * accorde au moins une permission RBAC". Avant cette colonne, AuthService::
 * managedStoreIds()/isManager() se basaient sur roleGrantsAnyPermission() —
 * n'importe quelle permission (même en libre-service, ex. photos.create pour
 * qu'un employé poste sa propre photo) faisait basculer tout le monde en
 * navigation manager, ce qui n'a jamais été l'intention (voir CHANGELOG).
 *
 * Défaut à 0 pour tous les rôles existants (y compris personnalisés) — à
 * l'Owner de cocher explicitement les rôles qui doivent voir la navigation
 * manager, indépendamment des permissions qu'ils accordent. Seul le rôle
 * système "manager" (seedé) est basculé à 1 ici pour préserver son
 * comportement actuel sans action manuelle.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasColumn('roles', 'is_manager')) {
            $this->schema()->table('roles', function (Blueprint $table) {
                $table->integer('is_manager')->default(0)->after('is_system');
            });
        }

        $this->capsule->getConnection()
            ->table('roles')
            ->where('slug', 'manager')
            ->update(['is_manager' => 1]);
    }

    public function down(): void
    {
        $this->schema()->table('roles', function (Blueprint $table) {
            $table->dropColumn('is_manager');
        });
    }
};

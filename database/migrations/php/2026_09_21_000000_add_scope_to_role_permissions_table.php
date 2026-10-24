<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Permet de rendre une permission accordée à un rôle "globale" (toutes les
 * boutiques) indépendamment de la portée (scope_type/scope_id) de chaque
 * affectation individuelle de ce rôle — voir PermissionService::
 * permissionIsGlobalOnRole(). 'local' (défaut) = comportement actuel,
 * inchangé pour toutes les lignes existantes.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('role_permissions', 'scope')) {
            return;
        }
        $this->schema()->table('role_permissions', function (Blueprint $table) {
            $table->string('scope')->default('local')->after('permission_key');
        });
    }

    public function down(): void
    {
        $this->schema()->table('role_permissions', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};

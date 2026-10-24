<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * users.is_admin et store_user.role/is_manager sont des colonnes historiques
 * gardées en double-écriture depuis la bascule vers le RBAC dynamique
 * (role_assignments, juillet 2026, task/mermission.md phase 3). AuthService/
 * PermissionService n'en ont jamais dépendu pour une décision d'autorisation
 * (role_assignments fait déjà foi) ; seuls restaient de l'affichage/tri et un
 * sélecteur de formulaire, migrés vers le RBAC dynamique dans cette même
 * passe (phase 4). Rien ne les lit ni ne les écrit plus.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('users', 'is_admin')) {
            $this->schema()->table('users', function (Blueprint $table) {
                $table->dropColumn('is_admin');
            });
        }
        if ($this->schema()->hasColumn('store_user', 'role')) {
            // L'index composite (store_id, role) référence la colonne : le
            // driver ne peut pas la dropper tant qu'il existe encore. Son nom
            // dépend de l'historique de la base (convention Laravel sur une
            // base créée depuis le set de migrations consolidé, nom explicite
            // hérité de l'ancien schéma sur une base plus ancienne) : on le
            // retrouve par introspection plutôt que de le supposer.
            $this->dropRoleIndex();
            $this->schema()->table('store_user', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
        if ($this->schema()->hasColumn('store_user', 'is_manager')) {
            $this->schema()->table('store_user', function (Blueprint $table) {
                $table->dropColumn('is_manager');
            });
        }
    }

    public function down(): void
    {
        // Colonnes legacy retirées après migration complète vers le RBAC
        // dynamique (role_assignments) : rien à recréer, plus aucun code ne
        // les lit ni ne les écrit.
    }

    private function dropRoleIndex(): void
    {
        $connection = $this->capsule->getConnection();
        $name = $connection->getDriverName() === 'sqlite'
            ? $this->findSqliteRoleIndexName($connection)
            : $this->findMysqlRoleIndexName($connection);

        if ($name === null) {
            return;
        }

        $this->schema()->table('store_user', function (Blueprint $table) use ($name) {
            $table->dropIndex($name);
        });
    }

    private function findSqliteRoleIndexName($connection): ?string
    {
        $indexes = $connection->select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'store_user'"
        );
        foreach ($indexes as $index) {
            $quotedName = '"' . str_replace('"', '""', $index->name) . '"';
            $columns = array_map(
                static fn ($col) => $col->name,
                $connection->select("PRAGMA index_info({$quotedName})")
            );
            if ($columns === ['store_id', 'role']) {
                return $index->name;
            }
        }
        return null;
    }

    private function findMysqlRoleIndexName($connection): ?string
    {
        $rows = $connection->select(
            'SELECT DISTINCT index_name FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['store_user', 'role']
        );
        return $rows[0]->index_name ?? null;
    }
};

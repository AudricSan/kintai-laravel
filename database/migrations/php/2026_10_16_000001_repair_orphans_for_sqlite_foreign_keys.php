<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Réparation des lignes orphelines avant l'activation des clés étrangères SQLite (audit du 13/10/2026, M8).
 *
 * Jusqu'ici SQLite n'appliquait pas les clés étrangères : supprimer un compte ou un magasin laissait ses shifts,
 * pointages, rapports… pointer vers un parent disparu. Une fois les clés actives, modifier une de ces lignes
 * échouerait. Choix du propriétaire du projet : conserver cet historique.
 *
 * - Compte ou magasin disparu : recréé comme parent « fantôme », déjà anonymisé (compte) ou supprimé (magasin),
 *   avec le même identifiant ; l'historique qui le référence reste intact, comme après une suppression faite
 *   aujourd'hui (UserAnonymizer, suppression douce des magasins).
 * - Autre orphelin (ex. demande liée à un shift disparu) : règle de la clé, comme MySQL l'aurait appliquée —
 *   suppression si ON DELETE CASCADE, mise à null si SET NULL ou si la colonne l'accepte, suppression sinon.
 *   Les passes se répètent jusqu'à stabilité pour propager les cascades sur plusieurs niveaux.
 *
 * Sans effet sous MySQL, qui applique déjà les clés étrangères.
 */
return new class($this->capsule) extends Migration {
    private const RESTORED_PARENTS = ['users', 'stores'];
    private const MAX_PASSES = 10;

    public function up(): void
    {
        $db = $this->capsule->getConnection();
        if ($db->getDriverName() !== 'sqlite') {
            return;
        }

        $edges = $this->foreignKeys();
        $this->restoreMissingParents($edges);

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            if ($this->fixOrphans($edges) === 0) {
                break;
            }
        }
    }

    public function down(): void
    {
        // Irréversible : les parents recréés et les lignes corrigées ne se distinguent plus.
    }

    /**
     * Clés étrangères simples (une colonne) de toutes les tables.
     *
     * @return list<array{table: string, column: string, parent: string, parentColumn: string, onDelete: string, nullable: bool}>
     */
    private function foreignKeys(): array
    {
        $db = $this->capsule->getConnection();
        $tables = array_map(
            fn($r) => $r->name,
            $db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name != 'migrations'")
        );

        $edges = [];
        foreach ($tables as $table) {
            $nullable = [];
            foreach ($db->select('PRAGMA table_info("' . $table . '")') as $col) {
                $nullable[$col->name] = (int) $col->notnull === 0;
            }
            $byId = [];
            foreach ($db->select('PRAGMA foreign_key_list("' . $table . '")') as $fk) {
                $byId[$fk->id][] = $fk;
            }
            foreach ($byId as $parts) {
                $fk = $parts[0];
                if (count($parts) !== 1 || !in_array($fk->table, $tables, true)) {
                    continue; // clé composite ou table parente absente : hors périmètre
                }
                $edges[] = [
                    'table'        => $table,
                    'column'       => $fk->from,
                    'parent'       => $fk->table,
                    'parentColumn' => $fk->to ?: 'id',
                    'onDelete'     => strtoupper((string) ($fk->on_delete ?: 'NO ACTION')),
                    'nullable'     => $nullable[$fk->from] ?? false,
                ];
            }
        }
        return $edges;
    }

    /** Requête des lignes de l'enfant dont la valeur de clé ne correspond à aucun parent. */
    private function orphans(array $edge)
    {
        $db = $this->capsule->getConnection();
        return $db->table($edge['table'])
            ->whereNotNull($edge['column'])
            ->whereNotIn($edge['column'], $db->table($edge['parent'])->select($edge['parentColumn']));
    }

    private function restoreMissingParents(array $edges): void
    {
        $db = $this->capsule->getConnection();
        $now = date('Y-m-d H:i:s');

        foreach (self::RESTORED_PARENTS as $parent) {
            $missing = [];
            foreach ($edges as $edge) {
                if ($edge['parent'] !== $parent || $edge['parentColumn'] !== 'id') {
                    continue;
                }
                foreach ($this->orphans($edge)->distinct()->pluck($edge['column']) as $id) {
                    if ((int) $id > 0) {
                        $missing[(int) $id] = true;
                    }
                }
            }

            foreach (array_keys($missing) as $id) {
                $db->table($parent)->insert($parent === 'users' ? [
                    'id'                => $id,
                    'email'             => 'deleted-' . $id . '@kintai.local', // comme UserAnonymizer
                    'password_hash'     => '',
                    'first_name'        => '[Supprimé]',
                    'last_name'         => '',
                    'display_name'      => 'Utilisateur supprimé',
                    'is_active'         => 0,
                    'show_in_directory' => 0,
                    'deleted_at'        => $now,
                ] : [
                    'id'         => $id,
                    'name'       => 'Magasin supprimé #' . $id,
                    'code'       => $this->freeStoreCode('DEL-' . $id),
                    'is_active'  => 0,
                    'deleted_at' => $now,
                ]);
            }
        }
    }

    private function freeStoreCode(string $code): string
    {
        $db = $this->capsule->getConnection();
        $candidate = $code;
        for ($i = 2; $db->table('stores')->where('code', $candidate)->exists(); $i++) {
            $candidate = $code . '-' . $i;
        }
        return $candidate;
    }

    /** Une passe sur toutes les clés ; renvoie le nombre de lignes modifiées ou supprimées. */
    private function fixOrphans(array $edges): int
    {
        $changed = 0;
        foreach ($edges as $edge) {
            $setNull = $edge['onDelete'] === 'SET NULL'
                || ($edge['onDelete'] !== 'CASCADE' && $edge['nullable']);

            $changed += $setNull
                ? $this->orphans($edge)->update([$edge['column'] => null])
                : $this->orphans($edge)->delete();
        }
        return $changed;
    }
};

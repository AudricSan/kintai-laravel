<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\PasswordHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nettoie les lignes orphelines d'une base importée (ex. dump de prod copié en local) : lignes dont la clé étrangère
 * pointe vers un parent qui n'existe plus (le cas le plus fréquent étant des users supprimés, dont les lignes
 * dépendantes — shifts, store_user, role_assignments, etc. — ont survécu car SQLite n'applique pas onDelete('cascade')
 * sans clés étrangères actives).
 *
 * Le schéma réel (PRAGMA foreign_key_list / table_info) est introspecté plutôt que relu depuis les migrations : il
 * reflète fidèlement les onDelete déclarés (CASCADE / SET NULL / RESTRICT) table par table, et les tables sont traitées
 * dans l'ordre topologique (parent avant enfant) pour que les cascades multi-niveaux se propagent en un seul passage.
 * Les colonnes en RESTRICT / NO ACTION ne sont jamais modifiées : les lignes concernées sont seulement signalées.
 *
 * Ne fonctionne qu'avec le pilote sqlite (introspection PRAGMA).
 */
final class CleanOrphansCommand extends Command
{
    protected $signature = 'kintai:clean-orphans
        {--apply : applique les suppressions et mises à NULL (sans cette option, simple simulation)}
        {--anonymize : anonymise aussi les données personnelles des utilisateurs restants}';

    protected $description = 'Nettoie les lignes orphelines d\'une base SQLite importée (simulation par défaut)';

    public function handle(): int
    {
        $apply     = (bool) $this->option('apply');
        $anonymize = (bool) $this->option('anonymize');

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            $this->error('Cette commande ne supporte que le pilote sqlite (introspection via PRAGMA foreign_key_list).');

            return self::FAILURE;
        }

        $pdo = $connection->getPdo();
        $this->line($apply ? 'Mode : APPLICATION (les modifications seront écrites)' : 'Mode : SIMULATION (aucune modification, ajouter --apply pour exécuter)');
        $this->newLine();

        $tables = $this->tables();
        $primaryKeys = [];
        foreach ($tables as $table) {
            $primaryKeys[$table] = $this->primaryKey($table);
        }

        $edges = $this->edges($tables, $primaryKeys);
        $order = $this->topologicalOrder($tables, $edges);
        if ($order === null) {
            return self::FAILURE;
        }

        $report = [];
        $pdo->beginTransaction();
        try {
            foreach ($order as $childTable) {
                foreach ($edges[$childTable] ?? [] as $fk) {
                    $this->sweep($childTable, $fk, $apply, $report);
                }
            }
            if ($anonymize) {
                $this->anonymize($apply);
            }
            $apply ? $pdo->commit() : $pdo->rollBack();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->error('Erreur, transaction annulée : ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->summary($report);
        if (!$apply) {
            $this->newLine();
            $this->line('Aucune modification écrite (simulation). Relancer avec --apply pour appliquer.');
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_map(
            static fn(object $row): string => $row->name,
            DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name != 'migrations' ORDER BY name"),
        );
    }

    private function primaryKey(string $table): string
    {
        foreach (DB::select('PRAGMA table_info("' . $table . '")') as $column) {
            if ((int) $column->pk === 1) {
                return $column->name;
            }
        }

        return 'id';
    }

    /**
     * Clés étrangères par table enfant.
     *
     * @param list<string>          $tables
     * @param array<string, string> $primaryKeys
     * @return array<string, list<array{column: string, parentTable: string, parentColumn: string, action: string}>>
     */
    private function edges(array $tables, array $primaryKeys): array
    {
        $edges = [];
        foreach ($tables as $table) {
            foreach (DB::select('PRAGMA foreign_key_list("' . $table . '")') as $fk) {
                if (!in_array($fk->table, $tables, true)) {
                    continue;
                }
                $edges[$table][] = [
                    'column'       => $fk->from,
                    'parentTable'  => $fk->table,
                    'parentColumn' => $fk->to ?: $primaryKeys[$fk->table],
                    'action'       => strtoupper((string) ($fk->on_delete ?: 'NO ACTION')),
                ];
            }
        }

        return $edges;
    }

    /**
     * Tri topologique (parent avant enfant) ; null (et message d'erreur) si les clés étrangères forment un cycle.
     *
     * @param list<string> $tables
     * @return list<string>|null
     */
    private function topologicalOrder(array $tables, array $edges): ?array
    {
        $inDegree   = array_fill_keys($tables, 0);
        $dependents = array_fill_keys($tables, []);
        foreach ($edges as $childTable => $foreignKeys) {
            foreach (array_unique(array_column($foreignKeys, 'parentTable')) as $parentTable) {
                $inDegree[$childTable]++;
                $dependents[$parentTable][] = $childTable;
            }
        }

        $queue = array_keys(array_filter($inDegree, static fn(int $degree): bool => $degree === 0));
        sort($queue);
        $order = [];
        while ($queue !== []) {
            $node    = array_shift($queue);
            $order[] = $node;
            foreach ($dependents[$node] as $dependent) {
                if (--$inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if (count($order) !== count($tables)) {
            $this->error('Cycle détecté dans le graphe de clés étrangères, tables non résolues : ' . implode(', ', array_diff($tables, $order)));

            return null;
        }

        return $order;
    }

    /**
     * @param array{column: string, parentTable: string, parentColumn: string, action: string} $fk
     * @param array<string, array<string, int>> $report
     */
    private function sweep(string $childTable, array $fk, bool $apply, array &$report): void
    {
        $column       = $fk['column'];
        $parentTable  = $fk['parentTable'];
        $parentColumn = $fk['parentColumn'];

        $where = sprintf('"%s" IS NOT NULL AND "%s" NOT IN (SELECT "%s" FROM "%s")', $column, $column, $parentColumn, $parentTable);
        $count = (int) DB::scalar(sprintf('SELECT COUNT(*) FROM "%s" WHERE %s', $childTable, $where));
        if ($count === 0) {
            return;
        }

        $link = sprintf('%s.%s -> %s.%s introuvable (%d ligne(s))', $childTable, $column, $parentTable, $parentColumn, $count);
        switch ($fk['action']) {
            case 'CASCADE':
                $this->line('[SUPPRESSION] ' . $link);
                if ($apply) {
                    DB::statement(sprintf('DELETE FROM "%s" WHERE %s', $childTable, $where));
                }
                $report[$childTable]['deleted'] = ($report[$childTable]['deleted'] ?? 0) + $count;
                break;

            case 'SET NULL':
                $this->line('[MISE A NULL] ' . $link);
                if ($apply) {
                    DB::statement(sprintf('UPDATE "%s" SET "%s" = NULL WHERE %s', $childTable, $column, $where));
                }
                $report[$childTable]['nulled'] = ($report[$childTable]['nulled'] ?? 0) + $count;
                break;

            default: // RESTRICT / NO ACTION : jamais touché automatiquement
                $this->warn(sprintf('[A REVOIR MANUELLEMENT] %s, onDelete=%s : non modifié', $link, $fk['action']));
                $report[$childTable]['blocked'] = ($report[$childTable]['blocked'] ?? 0) + $count;
        }
    }

    /** Remplace les données personnelles des utilisateurs restants par des valeurs neutres. */
    private function anonymize(bool $apply): void
    {
        $userIds = DB::table('users')->orderBy('id')->pluck('id')->all();
        $this->newLine();
        $this->line(sprintf('[ANONYMISATION] %d utilisateur(s)', count($userIds)));
        if (!$apply) {
            return;
        }

        foreach ($userIds as $id) {
            DB::table('users')->where('id', $id)->update([
                'email'              => "user{$id}@example.invalid",
                'password_hash'      => PasswordHasher::hash(bin2hex(random_bytes(8))),
                'first_name'         => 'Utilisateur',
                'last_name'          => (string) $id,
                'display_name'       => "Utilisateur {$id}",
                'furigana_last_name' => null,
                'furigana_first_name' => null,
                'phone'              => null,
                'mobile_phone'       => null,
                'guarantor_name'     => null,
                'guarantor_phone'    => null,
                'birth_date'         => null,
                'postal_code'        => null,
                'address'            => null,
                'gender'             => null,
                'tax_classification' => null,
                'education'          => null,
                'avatar_path'        => null,
                'bio'                => null,
                'skills'             => null,
                'languages_spoken'   => null,
                'hobbies'            => null,
                'employee_code'      => null,
            ]);
        }
    }

    /** @param array<string, array<string, int>> $report */
    private function summary(array $report): void
    {
        $this->newLine();
        $this->line('--- Résumé ---');
        if ($report === []) {
            $this->line('Aucune ligne orpheline trouvée.');

            return;
        }

        $totals = ['deleted' => 0, 'nulled' => 0, 'blocked' => 0];
        foreach ($report as $table => $counts) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $counts[$key] ?? 0;
            }
            $this->line(sprintf('  %-30s supprimées=%-4d nullifiées=%-4d a_revoir=%-4d', $table, $counts['deleted'] ?? 0, $counts['nulled'] ?? 0, $counts['blocked'] ?? 0));
        }
        $this->line(sprintf('  TOTAL : supprimées=%d, nullifiées=%d, à revoir manuellement=%d', $totals['deleted'], $totals['nulled'], $totals['blocked']));
    }
}

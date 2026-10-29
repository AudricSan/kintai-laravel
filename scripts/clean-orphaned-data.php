<?php

declare(strict_types=1);

/**
 * Nettoie les lignes orphelines d'une base importée (ex. dump de prod copié en
 * local) : lignes dont la clé étrangère pointe vers un parent qui n'existe
 * plus (le cas le plus fréquent étant des users supprimés côté users, mais
 * dont les lignes dépendantes — shifts, store_user, role_assignments, etc. —
 * ont survécu car SQLite ici n'applique pas réellement onDelete('cascade')).
 *
 * Le schéma réel de la base (PRAGMA foreign_key_list / table_info) est
 * introspecté directement plutôt que relu depuis les fichiers de migration :
 * il reflète fidèlement les onDelete déclarés (CASCADE / SET NULL / RESTRICT)
 * table par table, et les tables sont traitées dans l'ordre topologique de
 * dépendance (parent avant enfant) pour que les cascades multi-niveaux
 * (ex. shifts -> shift_claims -> ...) se propagent correctement en un seul
 * passage.
 *
 * Les colonnes en onDelete=RESTRICT (ou NO ACTION) ne sont jamais modifiées
 * automatiquement : les lignes concernées sont seulement signalées, une revue
 * manuelle est nécessaire.
 *
 * Usage :
 *   php scripts/clean-orphaned-data.php               # dry-run (rien n'est modifié)
 *   php scripts/clean-orphaned-data.php --apply        # applique les suppressions/mises à NULL
 *   php scripts/clean-orphaned-data.php --anonymize            # + anonymise les PII des users restants (dry-run)
 *   php scripts/clean-orphaned-data.php --apply --anonymize    # + anonymise réellement
 *
 * Ne fonctionne qu'avec le driver sqlite (introspection PRAGMA).
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/bootstrap/standalone.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$apply = in_array('--apply', $argv, true);
$anonymize = in_array('--anonymize', $argv, true);

$app = require BASE_PATH . '/bootstrap/boot.php';
$container = $app;

/** @var \Illuminate\Database\Connection $connection */
$connection = $container->make(Capsule::class)->getConnection();
if ($connection->getDriverName() !== 'sqlite') {
    fwrite(STDERR, "Ce script ne supporte que le driver sqlite (introspection via PRAGMA foreign_key_list).\n");
    exit(1);
}

/** @var PDO $pdo */
$pdo = $connection->getPdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo $apply ? "Mode : APPLICATION (les modifications seront écrites)\n" : "Mode : DRY-RUN (aucune modification, ajoute --apply pour exécuter)\n";
echo "\n";

// ---------------------------------------------------------------------
// 1. Introspection du schéma réel de la base
// ---------------------------------------------------------------------

function listTables(PDO $pdo): array
{
    return $pdo->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name != 'migrations' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);
}

function primaryKeyColumn(PDO $pdo, string $table): string
{
    $columns = $pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        if ((int) $column['pk'] === 1) {
            return $column['name'];
        }
    }
    return 'id';
}

$tables = listTables($pdo);
$primaryKeys = [];
foreach ($tables as $table) {
    $primaryKeys[$table] = primaryKeyColumn($pdo, $table);
}

// childTable => [ ['column' => ..., 'parentTable' => ..., 'parentColumn' => ..., 'onDelete' => ...], ... ]
$edges = [];
foreach ($tables as $table) {
    $foreignKeys = $pdo->query('PRAGMA foreign_key_list("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($foreignKeys as $fk) {
        $parentTable = $fk['table'];
        if (!in_array($parentTable, $tables, true)) {
            continue; // référence vers une table hors du périmètre connu (aucun cas ici)
        }
        $edges[$table][] = [
            'column' => $fk['from'],
            'parentTable' => $parentTable,
            'parentColumn' => $fk['to'] ?: $primaryKeys[$parentTable],
            'onDelete' => strtoupper((string) ($fk['on_delete'] ?: 'NO ACTION')),
        ];
    }
}

// ---------------------------------------------------------------------
// 2. Tri topologique (parent avant enfant) pour propager les cascades
//    multi-niveaux en un seul passage
// ---------------------------------------------------------------------

$inDegree = array_fill_keys($tables, 0);
$dependents = array_fill_keys($tables, []);
foreach ($edges as $childTable => $foreignKeys) {
    foreach (array_unique(array_column($foreignKeys, 'parentTable')) as $parentTable) {
        $inDegree[$childTable]++;
        $dependents[$parentTable][] = $childTable;
    }
}

$queue = array_keys(array_filter($inDegree, static fn (int $d): bool => $d === 0));
sort($queue);
$order = [];
while ($queue !== []) {
    $node = array_shift($queue);
    $order[] = $node;
    foreach ($dependents[$node] as $dependent) {
        if (--$inDegree[$dependent] === 0) {
            $queue[] = $dependent;
        }
    }
}

if (count($order) !== count($tables)) {
    $missing = array_diff($tables, $order);
    fwrite(STDERR, "Cycle détecté dans le graphe de clés étrangères, tables non résolues : " . implode(', ', $missing) . "\n");
    exit(1);
}

// ---------------------------------------------------------------------
// 3. Balayage des orphelins, table par table, dans l'ordre topologique
// ---------------------------------------------------------------------

$report = []; // table => ['deleted' => n, 'nulled' => n, 'blocked' => n]

$pdo->beginTransaction();
try {
    foreach ($order as $childTable) {
        foreach ($edges[$childTable] ?? [] as $fk) {
            $column = $fk['column'];
            $parentTable = $fk['parentTable'];
            $parentColumn = $fk['parentColumn'];

            $countSql = sprintf(
                'SELECT COUNT(*) FROM "%s" WHERE "%s" IS NOT NULL AND "%s" NOT IN (SELECT "%s" FROM "%s")',
                $childTable,
                $column,
                $column,
                $parentColumn,
                $parentTable
            );
            $orphanCount = (int) $pdo->query($countSql)->fetchColumn();
            if ($orphanCount === 0) {
                continue;
            }

            $whereSql = sprintf(
                '"%s" IS NOT NULL AND "%s" NOT IN (SELECT "%s" FROM "%s")',
                $column,
                $column,
                $parentColumn,
                $parentTable
            );

            switch ($fk['onDelete']) {
                case 'CASCADE':
                    printf("[SUPPRESSION] %s.%s -> %s.%s introuvable (%d ligne(s))\n", $childTable, $column, $parentTable, $parentColumn, $orphanCount);
                    if ($apply) {
                        $pdo->exec(sprintf('DELETE FROM "%s" WHERE %s', $childTable, $whereSql));
                    }
                    $report[$childTable]['deleted'] = ($report[$childTable]['deleted'] ?? 0) + $orphanCount;
                    break;

                case 'SET NULL':
                    printf("[MISE A NULL] %s.%s -> %s.%s introuvable (%d ligne(s))\n", $childTable, $column, $parentTable, $parentColumn, $orphanCount);
                    if ($apply) {
                        $pdo->exec(sprintf('UPDATE "%s" SET "%s" = NULL WHERE %s', $childTable, $column, $whereSql));
                    }
                    $report[$childTable]['nulled'] = ($report[$childTable]['nulled'] ?? 0) + $orphanCount;
                    break;

                default: // RESTRICT / NO ACTION : jamais touché automatiquement
                    printf(
                        "[A REVOIR MANUELLEMENT] %s.%s -> %s.%s introuvable (%d ligne(s)), onDelete=%s : non modifié\n",
                        $childTable,
                        $column,
                        $parentTable,
                        $parentColumn,
                        $orphanCount,
                        $fk['onDelete']
                    );
                    $report[$childTable]['blocked'] = ($report[$childTable]['blocked'] ?? 0) + $orphanCount;
                    break;
            }
        }
    }

    // -------------------------------------------------------------
    // 4. Anonymisation optionnelle des PII des users restants
    // -------------------------------------------------------------

    if ($anonymize) {
        $userIds = $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        printf("\n[ANONYMISATION] %d user(s)\n", count($userIds));

        if ($apply) {
            $stmt = $pdo->prepare(
                'UPDATE users SET
                    email = :email,
                    password_hash = :password_hash,
                    first_name = :first_name,
                    last_name = :last_name,
                    display_name = :display_name,
                    furigana = NULL,
                    phone = NULL,
                    mobile_phone = NULL,
                    guarantor_name = NULL,
                    guarantor_phone = NULL,
                    birth_date = NULL,
                    postal_code = NULL,
                    address = NULL,
                    gender = NULL,
                    tax_classification = NULL,
                    education = NULL,
                    profile_image = NULL,
                    avatar_path = NULL,
                    bio = NULL,
                    skills = NULL,
                    languages_spoken = NULL,
                    hobbies = NULL,
                    employee_code = NULL
                WHERE id = :id'
            );
            foreach ($userIds as $id) {
                $stmt->execute([
                    ':email' => "user{$id}@example.invalid",
                    ':password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT, ['cost' => 12]),
                    ':first_name' => 'Utilisateur',
                    ':last_name' => (string) $id,
                    ':display_name' => "Utilisateur {$id}",
                    ':id' => $id,
                ]);
            }
        }
    }

    if ($apply) {
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Erreur, transaction annulée : ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------
// 5. Résumé
// ---------------------------------------------------------------------

echo "\n--- Résumé ---\n";
if ($report === []) {
    echo "Aucune ligne orpheline trouvée.\n";
} else {
    $totalDeleted = 0;
    $totalNulled = 0;
    $totalBlocked = 0;
    foreach ($report as $table => $counts) {
        $deleted = $counts['deleted'] ?? 0;
        $nulled = $counts['nulled'] ?? 0;
        $blocked = $counts['blocked'] ?? 0;
        $totalDeleted += $deleted;
        $totalNulled += $nulled;
        $totalBlocked += $blocked;
        printf("  %-30s supprimées=%-4d nullifiées=%-4d a_revoir=%-4d\n", $table, $deleted, $nulled, $blocked);
    }
    printf("  TOTAL : supprimées=%d, nullifiées=%d, à revoir manuellement=%d\n", $totalDeleted, $totalNulled, $totalBlocked);
}

if (!$apply) {
    echo "\nAucune modification écrite (dry-run). Relance avec --apply pour appliquer.\n";
}

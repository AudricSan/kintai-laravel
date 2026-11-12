<?php

namespace App\Backups;

use Illuminate\Database\Connection;
use PDO;
use Throwable;

/**
 * SQLite : copie logique (schéma puis données) entre la base courante et un fichier SQLite autonome.
 *
 * Ni `VACUUM INTO` ni `ATTACH`, qui sont refusés dans une transaction, ni copie du fichier, qui n'est pas cohérente si
 * la base est en cours d'écriture ou en mémoire : lire la base par une connexion donne un état cohérent. La
 * restauration est atomique (une transaction, DDL compris) : en cas d'erreur, la base courante est inchangée.
 */
class SqliteDumper implements DatabaseDumper
{
    public function __construct(private readonly Connection $connection) {}

    public function driver(): string
    {
        return 'sqlite';
    }

    public function fileName(): string
    {
        return 'database.sqlite';
    }

    public function dump(string $path): void
    {
        $target = null;

        try {
            $target = $this->open($path);
            $target->exec('PRAGMA journal_mode = OFF');
            $target->beginTransaction();
            $this->copy($this->connection->getPdo(), $target, false);
            $target->commit();
        } catch (Throwable $e) {
            throw new BackupException(__('The database could not be exported.'), 0, $e);
        } finally {
            $target = null;
        }
    }

    public function restore(string $path): void
    {
        try {
            $source = $this->open($path);

            if ($source->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new BackupException(__('The database in this backup is damaged.'));
            }
        } catch (BackupException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BackupException(__('The database in this backup cannot be read.'), 0, $e);
        }

        $pdo = $this->connection->getPdo();
        $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1;
        // L'option ne se change pas dans une transaction : sinon, les contraintes sont seulement différées.
        $switched = $foreignKeys && $this->connection->transactionLevel() === 0;

        try {
            if ($switched) {
                $pdo->exec('PRAGMA foreign_keys = OFF');
            }

            $this->connection->transaction(function () use ($pdo, $source): void {
                $pdo->exec('PRAGMA defer_foreign_keys = ON');
                $this->copy($source, $pdo, true);
            });
        } catch (Throwable $e) {
            throw new BackupException(__('The database could not be restored.'), 0, $e);
        } finally {
            if ($switched) {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
    }

    private function open(string $path): PDO
    {
        return new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * Copie schéma et données de `$from` vers `$to` (qu'elle vide d'abord si `$wipe`).
     */
    private function copy(PDO $from, PDO $to, bool $wipe): void
    {
        $objects = $from->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY rowid")
            ->fetchAll(PDO::FETCH_ASSOC);

        if ($wipe) {
            $this->dropAll($to);
        }

        // Toutes les tables d'abord : une ligne ne peut pas référencer une table qui n'existe pas encore.
        foreach ($objects as $object) {
            if ($object['type'] === 'table') {
                $to->exec($object['sql']);
            }
        }

        foreach ($objects as $object) {
            if ($object['type'] === 'table') {
                $this->copyRows($from, $to, $object['name']);
            }
        }

        $this->copySequences($from, $to);

        foreach ($objects as $object) {
            if ($object['type'] !== 'table') {
                $to->exec($object['sql']);
            }
        }
    }

    /**
     * Supprime vues et tables, les tables qui en référencent d'autres d'abord (SQLite refuse de supprimer un parent
     * déjà disparu quand les clés étrangères sont actives).
     */
    private function dropAll(PDO $pdo): void
    {
        $views = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'view'")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($views as $view) {
            $pdo->exec('DROP VIEW IF EXISTS '.$this->quote((string) $view));
        }

        $pending = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'")->fetchAll(PDO::FETCH_COLUMN);
        $parents = [];

        foreach ($pending as $table) {
            foreach ($pdo->query('PRAGMA foreign_key_list('.$this->quote((string) $table).')') as $foreignKey) {
                $parents[$table][] = (string) $foreignKey['table'];
            }
        }

        while ($pending !== []) {
            $droppable = array_values(array_filter($pending, function (string $table) use ($pending, $parents): bool {
                foreach ($pending as $other) {
                    if ($other !== $table && in_array($table, $parents[$other] ?? [], true)) {
                        return false;
                    }
                }

                return true;
            }));

            // Cycle entre tables : on supprime dans l'ordre restant.
            $droppable = $droppable === [] ? $pending : $droppable;

            foreach ($droppable as $table) {
                $pdo->exec('DROP TABLE IF EXISTS '.$this->quote($table));
            }

            $pending = array_values(array_diff($pending, $droppable));
        }
    }

    private function copyRows(PDO $from, PDO $to, string $table): void
    {
        $rows = $from->query('SELECT * FROM '.$this->quote($table));
        $insert = null;

        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            $insert ??= $to->prepare(sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->quote($table),
                implode(', ', array_map($this->quote(...), array_keys($row))),
                implode(', ', array_fill(0, count($row), '?')),
            ));

            $insert->execute(array_values($row));
        }
    }

    /** Compteurs des clés AUTOINCREMENT (une ligne supprimée ne doit pas rendre son identifiant). */
    private function copySequences(PDO $from, PDO $to): void
    {
        $exists = fn (PDO $pdo): bool => $pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'sqlite_sequence'")->fetchColumn() !== false;

        if (! $exists($from) || ! $exists($to)) {
            return;
        }

        $to->exec('DELETE FROM sqlite_sequence');
        $insert = $to->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)');

        foreach ($from->query('SELECT name, seq FROM sqlite_sequence') as $row) {
            $insert->execute([$row['name'], $row['seq']]);
        }
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}

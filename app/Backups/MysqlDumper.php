<?php

namespace App\Backups;

use Illuminate\Database\Connection;
use PDO;
use Throwable;

/**
 * MySQL / MariaDB : export SQL en PHP pur (sans `mysqldump`, absent de nombreux hébergements mutualisés), porté de
 * l'ancienne version.
 *
 * Fichier produit : un script SQL où chaque instruction tient sur une ligne (ou plusieurs pour `CREATE TABLE`) et se
 * termine par une ligne `-- kintai:end` ; les retours à la ligne des valeurs sont échappés, donc cette ligne ne peut
 * jamais apparaître dans une donnée. Un fichier tronqué est reconnu : la dernière ligne est `-- kintai:complete`.
 *
 * Gros volumes : les données sortent par lots (`LIMIT` / `OFFSET` triés par clé primaire) et sont écrites au fil de
 * l'eau, sans jamais charger une table en mémoire ; les `INSERT` sont coupés avant d'approcher `max_allowed_packet`.
 * L'export lit un instantané cohérent (`START TRANSACTION WITH CONSISTENT SNAPSHOT`).
 */
class MysqlDumper implements DatabaseDumper
{
    public const STATEMENT_END = '-- kintai:end';

    public const COMPLETE = '-- kintai:complete';

    /** Taille approximative (octets) au-delà de laquelle un `INSERT` est fermé. */
    public const MAX_INSERT_BYTES = 524288;

    public function __construct(private readonly Connection $connection, private readonly int $batchRows = 500) {}

    public function driver(): string
    {
        return 'mysql';
    }

    public function fileName(): string
    {
        return 'database.sql';
    }

    public function dump(string $path): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new BackupException(__('The database could not be exported.'));
        }

        $snapshot = false;

        try {
            $snapshot = $this->beginSnapshot();

            $this->write($handle, "-- Kintai database dump\n-- Generated: ".gmdate('Y-m-d H:i:s')." UTC\n");
            $this->statement($handle, 'SET NAMES utf8mb4');
            $this->statement($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
            $this->statement($handle, 'SET FOREIGN_KEY_CHECKS = 0');

            foreach ($this->tables() as $table) {
                $this->dumpTable($handle, $table);
            }

            $this->statement($handle, 'SET FOREIGN_KEY_CHECKS = 1');
            $this->write($handle, self::COMPLETE."\n");
        } catch (Throwable $e) {
            throw new BackupException(__('The database could not be exported.'), 0, $e);
        } finally {
            if ($snapshot) {
                $this->endSnapshot();
            }

            fclose($handle);
        }
    }

    public function restore(string $path): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new BackupException(__('The database in this backup cannot be read.'));
        }

        $pdo = $this->pdo();
        $sqlMode = null;

        try {
            $sqlMode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $pdo->exec('SET NAMES utf8mb4');
            $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            // Les tables absentes de la sauvegarde (créées par une migration plus récente) doivent disparaître aussi.
            foreach ($this->tables() as $table) {
                $pdo->exec('DROP TABLE IF EXISTS '.$this->identifier($table));
            }

            $complete = $this->readStatements($handle, function (string $sql) use ($pdo): void {
                $pdo->exec($sql);
            });

            if (! $complete) {
                throw new BackupException(__('The database in this backup is incomplete.'));
            }
        } catch (BackupException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BackupException(__('The database could not be restored.'), 0, $e);
        } finally {
            fclose($handle);

            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

                if (is_string($sqlMode)) {
                    $pdo->exec('SET SQL_MODE = '.$pdo->quote($sqlMode));
                }
            } catch (Throwable) {
                // Connexion perdue : rien à rétablir.
            }
        }
    }

    /**
     * Lit un fichier produit par `dump()` et passe chaque instruction à `$callback`.
     *
     * @param  resource  $handle
     * @param  callable(string): void  $callback
     * @return bool Le fichier est-il complet (ligne finale présente, aucune instruction coupée) ?
     */
    public function readStatements($handle, callable $callback): bool
    {
        $buffer = '';
        $complete = false;

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");

            if ($line === self::STATEMENT_END) {
                if (trim($buffer) !== '') {
                    $callback(trim($buffer));
                }

                $buffer = '';

                continue;
            }

            if ($buffer === '' && $line === self::COMPLETE) {
                $complete = true;

                continue;
            }

            if ($buffer === '' && ($line === '' || str_starts_with($line, '-- '))) {
                continue;
            }

            $buffer .= $line."\n";
        }

        return $complete && trim($buffer) === '';
    }

    // -- Lecture de la base (surchargeable dans les tests) -----------------------------------------------------

    protected function pdo(): PDO
    {
        return $this->connection->getPdo();
    }

    protected function beginSnapshot(): bool
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return false;
        }

        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        return true;
    }

    protected function endSnapshot(): void
    {
        $this->pdo()->exec('COMMIT');
    }

    /**
     * @return list<string> Tables de base (les vues ne contiennent aucune donnée).
     */
    protected function tables(): array
    {
        $tables = [];

        foreach ($this->connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $values = array_values((array) $row);
            $tables[] = (string) $values[0];
        }

        return $tables;
    }

    protected function createStatement(string $table): string
    {
        $row = array_values((array) $this->connection->select('SHOW CREATE TABLE '.$this->identifier($table))[0]);

        return (string) $row[1];
    }

    /**
     * @return list<array{name: string, binary: bool}> Colonnes enregistrées (les colonnes générées sont recalculées).
     */
    protected function columns(string $table): array
    {
        $columns = [];

        foreach ($this->connection->select('SHOW COLUMNS FROM '.$this->identifier($table)) as $row) {
            $row = array_change_key_case((array) $row);

            if (str_contains(strtolower((string) ($row['extra'] ?? '')), 'generated')) {
                continue;
            }

            $columns[] = [
                'name' => (string) $row['field'],
                'binary' => preg_match('/blob|binary|^bit/i', (string) $row['type']) === 1,
            ];
        }

        return $columns;
    }

    /**
     * @return list<string> Colonnes de la clé primaire, dans l'ordre (tri stable des lots).
     */
    protected function primaryKey(string $table): array
    {
        $keys = [];

        foreach ($this->connection->select('SHOW KEYS FROM '.$this->identifier($table)." WHERE Key_name = 'PRIMARY'") as $row) {
            $row = array_change_key_case((array) $row);
            $keys[(int) $row['seq_in_index']] = (string) $row['column_name'];
        }

        ksort($keys);

        return array_values($keys);
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $order
     * @return list<array<string, mixed>>
     */
    protected function fetchBatch(string $table, array $columns, array $order, int $limit, int $offset): array
    {
        $sql = 'SELECT '.implode(', ', array_map($this->identifier(...), $columns)).' FROM '.$this->identifier($table);

        if ($order !== []) {
            $sql .= ' ORDER BY '.implode(', ', array_map($this->identifier(...), $order));
        }

        return array_map(fn (object $row): array => (array) $row, $this->connection->select($sql.' LIMIT '.$limit.' OFFSET '.$offset));
    }

    // -- Écriture ----------------------------------------------------------------------------------------------

    /**
     * @param  resource  $handle
     */
    private function dumpTable($handle, string $table): void
    {
        $this->statement($handle, 'DROP TABLE IF EXISTS '.$this->identifier($table));
        $this->statement($handle, $this->createStatement($table));

        $columns = $this->columns($table);

        if ($columns === []) {
            return;
        }

        $names = array_column($columns, 'name');
        $binary = array_column($columns, 'binary', 'name');
        $order = $this->primaryKey($table);
        $prefix = 'INSERT INTO '.$this->identifier($table).' ('.implode(', ', array_map($this->identifier(...), $names)).') VALUES ';
        $offset = 0;
        $pdo = $this->pdo();

        do {
            $rows = $this->fetchBatch($table, $names, $order, $this->batchRows, $offset);
            $offset += $this->batchRows;
            $values = [];
            $bytes = 0;

            foreach ($rows as $row) {
                $line = '(';
                $first = true;

                foreach ($names as $name) {
                    $line .= ($first ? '' : ', ').self::formatValue($pdo, $row[$name] ?? null, $binary[$name]);
                    $first = false;
                }

                $line .= ')';
                $values[] = $line;
                $bytes += strlen($line);

                if ($bytes >= self::MAX_INSERT_BYTES) {
                    $this->statement($handle, $prefix.implode(', ', $values));
                    $values = [];
                    $bytes = 0;
                }
            }

            if ($values !== []) {
                $this->statement($handle, $prefix.implode(', ', $values));
            }
        } while (count($rows) === $this->batchRows);
    }

    /**
     * Valeur SQL littérale : `NULL`, entier, hexadécimale pour le binaire, sinon chaîne échappée par le pilote
     * (retours à la ligne échappés, pour que chaque instruction reste découpable ligne par ligne).
     */
    public static function formatValue(PDO $pdo, mixed $value, bool $binary = false): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? var_export($value, true) : 'NULL';
        }

        $value = (string) $value;

        if ($binary) {
            return $value === '' ? "''" : '0x'.bin2hex($value);
        }

        return str_replace(["\r", "\n"], ['\\r', '\\n'], (string) $pdo->quote($value));
    }

    private function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * @param  resource  $handle
     */
    private function statement($handle, string $sql): void
    {
        $this->write($handle, $sql."\n".self::STATEMENT_END."\n");
    }

    /**
     * @param  resource  $handle
     */
    private function write($handle, string $contents): void
    {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new BackupException(__('The database could not be exported.'));
        }
    }
}

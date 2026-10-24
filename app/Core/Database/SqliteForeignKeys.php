<?php

declare(strict_types=1);

namespace kintai\Core\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * Clés étrangères SQLite : activées sur la connexion de l'application depuis l'audit du 13/10/2026 (M8), pour
 * que les règles onDelete (cascade, mise à null, refus) s'appliquent comme sous MySQL.
 *
 * Certaines opérations doivent les suspendre : une migration qui modifie une table la reconstruit sous SQLite
 * (copie, suppression, renommage), ce qui déclencherait les cascades sur les tables qui la référencent ; une
 * remise à zéro vide les tables dans un ordre quelconque. PRAGMA foreign_keys est sans effet dans une
 * transaction : à appeler hors transaction. L'état d'origine est rétabli, qu'il ait été actif ou non.
 */
final class SqliteForeignKeys
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function without(ConnectionInterface $connection, callable $callback): mixed
    {
        if (!method_exists($connection, 'getDriverName') || $connection->getDriverName() !== 'sqlite') {
            return $callback();
        }

        $wasEnabled = self::enabled($connection);
        if ($wasEnabled) {
            $connection->statement('PRAGMA foreign_keys = OFF');
        }
        try {
            return $callback();
        } finally {
            if ($wasEnabled) {
                $connection->statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    public static function enabled(ConnectionInterface $connection): bool
    {
        $row = $connection->selectOne('PRAGMA foreign_keys');
        return (int) (is_object($row) ? array_values((array) $row)[0] : ($row[0] ?? 0)) === 1;
    }
}

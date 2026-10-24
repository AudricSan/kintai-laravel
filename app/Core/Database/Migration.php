<?php

declare(strict_types=1);

namespace kintai\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

abstract class Migration
{
    protected Capsule $capsule;

    public function __construct(Capsule $capsule)
    {
        $this->capsule = $capsule;
    }

    abstract public function up(): void;
    abstract public function down(): void;

    /**
     * Une migration "seed" insère des données structurelles idempotentes (elle se
     * protège elle-même contre une double insertion, ex. "le rôle existe déjà ?")
     * plutôt que du schéma pur ou une migration de données à usage unique. Ce
     * marqueur permet à AppResetService::resetFactory() de savoir, sans connaître
     * aucun nom de migration, lesquelles doivent rejouer après avoir vidé les
     * données — sinon leur garde d'idempotence empêcherait tout reseed pour
     * toujours (la table migrations, elle, n'est jamais vidée par un reset).
     */
    public function isSeed(): bool
    {
        return false;
    }

    /**
     * Valeur par défaut d'une colonne TEXT valable sur les deux pilotes. MySQL refuse un littéral sur TEXT
     * (« BLOB, TEXT, GEOMETRY or JSON column can't have a default value ») : il faut une expression « ('…') »,
     * échappée par le pilote (un antislash est un caractère d'échappement dans un littéral MySQL). SQLite garde le
     * littéral, comme avant.
     */
    protected function textDefault(string $value): mixed
    {
        $connection = $this->capsule->getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            return $value;
        }
        return new \Illuminate\Database\Query\Expression('(' . $connection->getPdo()->quote($value) . ')');
    }

    protected function schema()
    {
        $schema = $this->capsule->getConnection()->getSchemaBuilder();
        // Clés primaires signées, comme les colonnes qui les référencent (voir PortableBlueprint) : sinon MySQL refuse
        // les clés étrangères.
        $schema->blueprintResolver(
            static fn($connection, string $table, ?\Closure $callback = null) => new PortableBlueprint($connection, $table, $callback)
        );
        return $schema;
    }
}

<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

/**
 * 2026_09_20_000000_drop_legacy_role_columns.php supposait le nom d'index par défaut
 * généré par Laravel (store_user_store_id_role_index) pour dropper l'index composite
 * (store_id, role) avant de supprimer la colonne role. Une base créée avant la
 * consolidation des migrations de juillet 2026 porte cet index sous un nom explicite
 * différent (ex. idx_store_user_role) : la migration échouait avec "no such index" et
 * ne supprimait ni l'index ni les colonnes legacy. La migration retrouve désormais le
 * nom réel de l'index par introspection plutôt que de le supposer.
 */
final class DropLegacyRoleColumnsIndexNameTest extends TestCase
{
    public function testDropsIndexRegardlessOfItsStoredName(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('users', function ($table) {
            $table->increments('id');
        });
        $schema->create('store_user', function ($table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->integer('user_id');
            $table->string('role')->default('staff');
            $table->integer('is_manager')->default(0);
        });
        $capsule->getConnection()->statement(
            'CREATE INDEX idx_store_user_role ON store_user (store_id, role)'
        );

        $harness = new class {
            public Capsule $capsule;

            public function load(string $file)
            {
                return require $file;
            }
        };
        $harness->capsule = $capsule;

        $migration = $harness->load(dirname(__DIR__, 2) . '/database/migrations/php/2026_09_20_000000_drop_legacy_role_columns.php');
        $migration->up();

        $this->assertFalse($schema->hasColumn('store_user', 'role'));
        $this->assertFalse($schema->hasColumn('store_user', 'is_manager'));

        $indexes = $capsule->getConnection()->select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'store_user'"
        );
        $this->assertSame([], $indexes);
    }
}

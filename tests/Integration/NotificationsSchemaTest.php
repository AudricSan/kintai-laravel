<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Repositories\DatabaseNotificationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Les installations créées avant le passage au système de migrations PHP ont une table
 * notifications à l'ancien schéma (reference_id/body/is_read, pas de colonne data) : la
 * migration de création (2026_06_09_000012) fait un hasTable() et ne s'y applique donc
 * jamais, si bien que toute notification (ex. soumission d'un rapport journalier) échouait
 * avec "no such column: data" alors même que l'action métier avait réussi. Ce test simule
 * cet état legacy avant de lancer les migrations, pour éviter la régression.
 */
final class NotificationsSchemaTest extends TestCase
{
    private function migratedCapsuleWithLegacyNotificationsTable(): Capsule
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('type', 60);
            $table->integer('reference_id')->nullable();
            $table->text('body')->default('');
            $table->integer('is_read')->default(0);
            $table->dateTime('created_at');
            $table->index(['user_id', 'is_read'], 'idx_notifications_user');
        });
        $capsule->table('notifications')->insert([
            'user_id'      => 7,
            'type'         => 'daily_report_submitted',
            'reference_id' => 42,
            'body'         => 'Un rapport journalier attend votre validation.',
            'is_read'      => 1,
            'created_at'   => '2026-01-01 10:00:00',
        ]);

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $capsuleProp = new \ReflectionProperty($runner, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($runner, $capsule);
        $pathProp = new \ReflectionProperty($runner, 'migrationsPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');

        $runner->run();

        return $capsule;
    }

    public function testLegacyRowIsPreservedAndNewNotificationsCanBeSaved(): void
    {
        $this->migratedCapsuleWithLegacyNotificationsTable();

        $repo = new DatabaseNotificationRepository();

        $legacy = $repo->findByUser(7)[0];
        $this->assertSame('Un rapport journalier attend votre validation.', $legacy['body']);
        $this->assertSame(42, $legacy['reference_id']);
        $this->assertSame(1, $legacy['is_read']);

        $saved = $repo->save([
            'user_id'      => 7,
            'type'         => 'daily_report_submitted',
            'reference_id' => 99,
            'body'         => 'Un nouveau rapport attend votre validation.',
            'is_read'      => 0,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->assertNotNull($saved['id']);
        $this->assertSame(0, $saved['is_read']);
        $this->assertSame(99, $saved['reference_id']);
    }

    public function testMigrationIsIdempotentOnRerun(): void
    {
        $capsule = $this->migratedCapsuleWithLegacyNotificationsTable();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $capsuleProp = new \ReflectionProperty($runner, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($runner, $capsule);
        $pathProp = new \ReflectionProperty($runner, 'migrationsPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');

        $this->assertSame(0, $runner->run());
    }

    private function runMigrations(Capsule $capsule): int
    {
        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $capsuleProp = new \ReflectionProperty($runner, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($runner, $capsule);
        $pathProp = new \ReflectionProperty($runner, 'migrationsPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');

        return $runner->run();
    }

    private function freshCapsule(): Capsule
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        return $capsule;
    }

    /**
     * Refonte d'octobre 2026 : sur une instance au schéma data/read_at, 2026_10_19_000000 éclate le JSON `data`
     * en vraies colonnes sans rien perdre (texte, référence, lien, état lu).
     */
    public function testDataJsonIsSplitIntoColumns(): void
    {
        $capsule = $this->freshCapsule();
        $capsule->getConnection()->getSchemaBuilder()->create('notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('type');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'read_at']);
        });
        $capsule->table('notifications')->insert([
            ['user_id' => 7, 'type' => 'shift_assigned', 'data' => json_encode(['body' => 'Nouveau shift', 'reference_id' => 12, 'link' => '/employee/shifts/day?start=2026-10-01']), 'read_at' => null, 'created_at' => '2026-10-01 10:00:00'],
            ['user_id' => 7, 'type' => 'timeoff_approved', 'data' => json_encode(['body' => 'Congé approuvé', 'reference_id' => null]), 'read_at' => '2026-10-02 08:00:00', 'created_at' => '2026-10-01 11:00:00'],
            ['user_id' => 7, 'type' => 'broken', 'data' => 'pas du json', 'read_at' => null, 'created_at' => '2026-10-01 12:00:00'],
        ]);

        $this->runMigrations($capsule);

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $this->assertFalse($schema->hasColumn('notifications', 'data'));
        $this->assertTrue($schema->hasIndex('notifications', ['user_id', 'id']));

        $rows = (new DatabaseNotificationRepository())->findByUser(7);
        $this->assertSame(['broken', 'timeoff_approved', 'shift_assigned'], array_column($rows, 'type'));
        $this->assertSame('', $rows[0]['body']);
        $this->assertSame('Congé approuvé', $rows[1]['body']);
        $this->assertSame(1, $rows[1]['is_read']);
        $this->assertNull($rows[1]['link']);
        $this->assertSame('Nouveau shift', $rows[2]['body']);
        $this->assertSame(12, $rows[2]['reference_id']);
        $this->assertSame('/employee/shifts/day?start=2026-10-01', $rows[2]['link']);
        $this->assertNull($rows[2]['body_key']);

        $this->assertSame(0, $this->runMigrations($capsule));
    }

    /** Instance neuve : la table est créée directement au schéma cible, les migrations correctives n'y touchent pas. */
    public function testFreshInstallGetsTargetSchemaDirectly(): void
    {
        $capsule = $this->freshCapsule();
        $this->runMigrations($capsule);

        $schema = $capsule->getConnection()->getSchemaBuilder();
        foreach (['body_key', 'params', 'body', 'link', 'reference_type', 'reference_id', 'read_at'] as $column) {
            $this->assertTrue($schema->hasColumn('notifications', $column), $column);
        }
        $this->assertFalse($schema->hasColumn('notifications', 'data'));
        $this->assertFalse($schema->hasColumn('notifications', 'is_read'));
    }
}

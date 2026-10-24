<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * sessions (session persistée en BD) n'a jamais eu la moindre implémentation (aucun modèle
 * Eloquent, aucun repository, aucune lecture/écriture nulle part) — les sessions sont
 * natives PHP, fichier. Une nouvelle installation ne doit plus créer cette table morte.
 *
 * remember_tokens, droppée en même temps le 2026-08-06 pour la même raison, a depuis reçu
 * une vraie implémentation ("rester connecté" 30 jours, voir AuthService) et est recréée par
 * 2026_08_07_000000_create_remember_tokens_table.php — elle n'a donc plus sa place ici.
 */
final class UnusedSessionsTableDroppedTest extends TestCase
{
    public function testFreshInstallDoesNotHaveThisTable(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $capsuleProp = new \ReflectionProperty($runner, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($runner, $capsule);
        $pathProp = new \ReflectionProperty($runner, 'migrationsPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');

        $runner->run();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $this->assertFalse($schema->hasTable('sessions'));
    }
}

<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
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
        $capsule = kintai_test_database();

        kintai_test_migrate();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $this->assertFalse($schema->hasTable('sessions'));
    }
}

<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * La migration 2026_10_16_000000 renseigne deleted_at sur les comptes anonymisés par l'ancienne suppression
 * personnelle (adresse deleted-{id}@kintai.local, inactifs), et seulement sur eux.
 */
final class MarkAnonymizedUsersAsDeletedTest extends TestCase
{
    private const MIGRATION = '/database/migrations/php/2026_10_16_000000_mark_anonymized_users_as_deleted.php';

    private Capsule $capsule;

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        foreach (['capsule' => $this->capsule, 'migrationsPath' => dirname(__DIR__, 2) . '/database/migrations/php'] as $prop => $value) {
            $p = new \ReflectionProperty($runner, $prop);
            $p->setValue($runner, $value);
        }
        $runner->run();
    }

    public function testOnlyAccountsAnonymizedBySelfDeletionAreMarked(): void
    {
        $db = $this->capsule->getConnection();
        $rows = [
            [1, 'deleted-1@kintai.local', 0],     // anonymisé : marqué
            [2, 'deleted-2@kintai.local', 1],     // actif : laissé
            [3, 'deleted-99@kintai.local', 0],    // adresse d'un autre identifiant : laissé
            [4, 'aiko@example.com', 0],           // simplement désactivé : laissé
        ];
        foreach ($rows as [$id, $email, $active]) {
            $db->table('users')->insert([
                'id' => $id, 'email' => $email, 'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B',
                'password_hash' => '', 'is_active' => $active, 'updated_at' => '2026-05-01 10:00:00',
            ]);
        }

        $loader = new class($this->capsule) {
            public function __construct(public Capsule $capsule) {}

            public function load(string $file): object
            {
                return require $file;
            }
        };
        $loader->load(dirname(__DIR__, 2) . self::MIGRATION)->up();

        $this->assertSame(
            [1 => '2026-05-01 10:00:00', 2 => null, 3 => null, 4 => null],
            $db->table('users')->orderBy('id')->pluck('deleted_at', 'id')->all(),
        );
    }
}

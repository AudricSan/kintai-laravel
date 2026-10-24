<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\Migration;
use PHPUnit\Framework\TestCase;

/**
 * Migration 2026_10_18_000002 : store_features passe d'une liste de fonctionnalités activées à des décisions par case.
 * Un magasin déjà configuré garde exactement son état (une fonctionnalité connue absente de sa liste devient une
 * décision « désactivée ») ; un magasin jamais configuré reste sans décision (tout actif).
 */
final class StoreFeatureDecisionsMigrationTest extends TestCase
{
    public function testConfiguredStoresKeepTheirStateAndOthersStayUnconfigured(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Schéma d'avant : liste des fonctionnalités activées seulement.
        $capsule->getConnection()->getSchemaBuilder()->create('store_features', function ($table) {
            $table->integer('store_id');
            $table->string('feature_key');
            $table->primary(['store_id', 'feature_key']);
        });
        $db = $capsule->getConnection();
        $db->table('store_features')->insert([
            ['store_id' => 1, 'feature_key' => 'photos'],   // magasin 1 configuré : « shifts » désactivé
        ]);

        /** @var Migration $migration */
        $migration = (function () use ($capsule) {
            $this->capsule = $capsule;
            return require dirname(__DIR__, 2) . '/database/migrations/php/2026_10_18_000002_store_feature_decisions.php';
        })->call(new class { public $capsule; });
        $migration->up();

        $rows = [];
        foreach ($db->table('store_features')->orderBy('store_id')->orderBy('feature_key')->get() as $r) {
            $rows[(int) $r->store_id][(string) $r->feature_key] = (bool) $r->enabled;
        }

        $this->assertTrue($rows[1]['photos'], 'décision existante conservée (active)');
        $this->assertFalse($rows[1]['shifts'], 'fonctionnalité connue absente de la liste : désactivée, comme avant');
        $this->assertArrayNotHasKey(2, $rows, 'magasin jamais configuré : aucune décision, tout reste actif');

        $migration->up(); // idempotente
        $this->assertSame(count($rows[1]), $db->table('store_features')->where('store_id', 1)->count());
    }
}

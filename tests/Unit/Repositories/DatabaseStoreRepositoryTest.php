<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\DatabaseStoreRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Domain\Eloquent\Store as EloquentStore;
use kintai\Domain\Eloquent\StoreFeature;
use kintai\Domain\Eloquent\StoreImportSetting;
use kintai\Domain\Eloquent\StoreDeductionSetting;

final class DatabaseStoreRepositoryTest extends TestCase
{
    private DatabaseStoreRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        
        $schema->create('stores', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->unique();
            $table->integer('is_active')->default(1);
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        $schema->create('store_features', function ($table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->string('feature_key');
            $table->boolean('enabled')->default(true);
        });

        $schema->create('store_import_settings', function ($table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->string('setting_name')->nullable();
        });

        $schema->create('store_deduction_settings', function ($table) {
            $table->increments('id');
            $table->integer('store_id');
            $table->string('setting_name')->nullable();
        });

        $this->repo = new DatabaseStoreRepository();
    }

    private function store(int $id, string $code = 'STORE01', int $active = 1, ?string $deletedAt = null): array
    {
        return ['id' => $id, 'code' => $code, 'name' => 'Test Store', 'is_active' => $active, 'deleted_at' => $deletedAt];
    }

    // -------------------------------------------------------------------------
    // findById()
    // -------------------------------------------------------------------------

    public function testFindByIdReturnsStore(): void
    {
        $store = EloquentStore::create(['name' => 'Test', 'code' => 'T01']);
        $found = $this->repo->findById($store->id);
        $this->assertNotNull($found);
        $this->assertEquals('T01', $found['code']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    // -------------------------------------------------------------------------
    // findByCode()
    // -------------------------------------------------------------------------

    public function testFindByCodeReturnsStore(): void
    {
        EloquentStore::create(['name' => 'Test', 'code' => 'T01']);
        $found = $this->repo->findByCode('T01');
        $this->assertNotNull($found);
        $this->assertEquals('T01', $found['code']);
    }

    public function testFindByCodeReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repo->findByCode('UNKNOWN'));
    }

    // -------------------------------------------------------------------------
    // findAll()
    // -------------------------------------------------------------------------

    public function testFindAllReturnsAllIncludingInactive(): void
    {
        EloquentStore::create(['name' => 'S1', 'code' => 'S1', 'is_active' => 1]);
        EloquentStore::create(['name' => 'S2', 'code' => 'S2', 'is_active' => 0]);

        $this->assertCount(2, $this->repo->findAll());
    }

    // -------------------------------------------------------------------------
    // findActive()
    // -------------------------------------------------------------------------

    public function testFindActiveReturnsOnlyActiveStores(): void
    {
        EloquentStore::create(['name' => 'S1', 'code' => 'S1', 'is_active' => 1]);
        EloquentStore::create(['name' => 'S2', 'code' => 'S2', 'is_active' => 0]);
        EloquentStore::create(['name' => 'S3', 'code' => 'S3', 'is_active' => 1, 'deleted_at' => date('Y-m-d H:i:s')]);

        $result = $this->repo->findActive();
        $this->assertCount(1, $result);
        $this->assertEquals('S1', $result[0]['code']);
    }

    // -------------------------------------------------------------------------
    // countActive()
    // -------------------------------------------------------------------------

    public function testCountActiveCountsOnlyActiveStores(): void
    {
        EloquentStore::create(['name' => 'S1', 'code' => 'S1', 'is_active' => 1]);
        EloquentStore::create(['name' => 'S2', 'code' => 'S2', 'is_active' => 0]);
        EloquentStore::create(['name' => 'S3', 'code' => 'S3', 'is_active' => 1, 'deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(1, $this->repo->countActive());
    }

    // -------------------------------------------------------------------------
    // save()
    // -------------------------------------------------------------------------

    public function testSaveCreatesStore(): void
    {
        $data = ['name' => 'New Store', 'code' => 'NEW01', 'is_active' => 1];
        $result = $this->repo->save($data);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('NEW01', $result['code']);
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    /** Audit M8 : suppression douce, la ligne reste (l'historique qui la référence aussi). */
    public function testDeleteSoftDeletesTheStoreAndFreesItsCode(): void
    {
        $store = EloquentStore::create(['name' => 'T', 'code' => 'T']);
        $count = $this->repo->delete($store->id);
        $this->assertEquals(1, $count);

        $row = EloquentStore::find($store->id);
        $this->assertNotNull($row);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(0, (int) $row->is_active);
        $this->assertSame('DEL-' . $store->id . '-T', $row->code);

        // Absent des listes et de la recherche par code, présent pour l'historique ; le code est réutilisable.
        $this->assertSame([], $this->repo->findAll());
        $this->assertNull($this->repo->findByCode('T'));
        $this->assertCount(1, $this->repo->findAllWithDeleted());
        $this->assertNotNull($this->repo->findById($store->id));
        EloquentStore::create(['name' => 'T2', 'code' => 'T']);
        $this->assertCount(1, $this->repo->findAll());

        // Une seconde suppression ne fait rien.
        $this->assertSame(0, $this->repo->delete($store->id));
    }

    public function testDeleteReturnsZeroWhenNotFound(): void
    {
        $this->assertSame(0, $this->repo->delete(999));
    }

    // -------------------------------------------------------------------------
    // Features / Settings
    // -------------------------------------------------------------------------

    public function testFeaturesPersistence(): void
    {
        $this->repo->saveFeatures(1, ['f1', 'f2']);
        $this->assertEquals(['f1', 'f2'], $this->repo->getFeatures(1));
        
        $this->repo->saveFeatures(1, ['f3']);
        $this->assertEquals(['f3'], $this->repo->getFeatures(1));
    }

    /** Décisions par case : une fonctionnalité jamais réglée est active, les autres décisions restent intactes. */
    public function testFeatureDecisionsOnlyTouchTheGivenKeys(): void
    {
        $this->assertSame([], $this->repo->getFeatures(2), 'jamais configuré : tout actif');

        // Fonctionnalité d'un bundle désactivé réglée « inactive », puis enregistrement du formulaire sans elle.
        $this->repo->saveFeatureDecisions(2, ['photos' => false]);
        $this->repo->saveFeatureDecisions(2, ['shifts' => true]);
        $this->assertSame(['photos' => false, 'shifts' => true], $this->repo->getFeatureDecisions(2));

        // Seule la décision « active » est enregistrée : tout reste actif.
        $this->repo->saveFeatureDecisions(3, ['shifts' => true, 'timeoff' => true]);
        $this->assertSame([], $this->repo->getFeatures(3));

        // « shifts » désactivé : les fonctionnalités proposées jamais réglées restent actives (ici aucune).
        $this->repo->saveFeatureDecisions(3, ['shifts' => false]);
        $this->assertSame(['timeoff'], $this->repo->getFeatures(3));
    }

    public function testImportSettingsPersistence(): void
    {
        $this->repo->saveImportSettings(1, ['setting_name' => 'val1']);
        $settings = $this->repo->getImportSettings(1);
        $this->assertEquals('val1', $settings['setting_name']);
    }

    public function testDeductionSettingsPersistence(): void
    {
        $this->repo->saveDeductionSettings(1, ['setting_name' => 'val1']);
        $settings = $this->repo->getDeductionSettings(1);
        $this->assertEquals('val1', $settings['setting_name']);
    }
}

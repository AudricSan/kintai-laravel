<?php
declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\DatabaseBundleRegistryRepository;
use Illuminate\Database\Capsule\Manager as Capsule;

final class DatabaseBundleRegistryRepositoryTest extends TestCase
{
    private DatabaseBundleRegistryRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('bundle_registries', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('url')->unique();
            $table->boolean('is_official')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        $this->repo = new DatabaseBundleRegistryRepository();
    }

    public function testAllReturnsEmptyArrayWhenNoRegistry(): void
    {
        $this->assertSame([], $this->repo->all());
    }

    public function testCreateThenAllReturnsTheNewRegistry(): void
    {
        $created = $this->repo->create('Registry officiel Kintai', 'https://example.test/registry.json');

        $this->assertSame('Registry officiel Kintai', $created['name']);
        $this->assertFalse($created['is_official']);
        $this->assertIsInt($created['id']);

        $all = $this->repo->all();
        $this->assertCount(1, $all);
        $this->assertSame($created['id'], $all[0]['id']);
    }

    public function testExistsByUrlDetectsDuplicates(): void
    {
        $this->repo->create('Registry A', 'https://example.test/a.json');

        $this->assertTrue($this->repo->existsByUrl('https://example.test/a.json'));
        $this->assertFalse($this->repo->existsByUrl('https://example.test/b.json'));
    }

    public function testFindReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->find(999));
    }

    public function testFindReturnsTheMatchingRow(): void
    {
        $created = $this->repo->create('Registry A', 'https://example.test/a.json');

        $found = $this->repo->find($created['id']);

        $this->assertNotNull($found);
        $this->assertSame('Registry A', $found['name']);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $created = $this->repo->create('Registry A', 'https://example.test/a.json');

        $this->repo->delete($created['id']);

        $this->assertNull($this->repo->find($created['id']));
        $this->assertSame([], $this->repo->all());
    }
}
